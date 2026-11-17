<?php
/**
 * Preload: visits the pages from the sitemap in the background so the cache is full before visitors
 * arrive, re-visits pages cleared after an edit, and loads the next page early when a visitor points
 * at a link (WordPress speculative loading).
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Preload.
 */
class Transara_Preload {

	const QUEUE = 'transara_preload_queue';
	const BATCH = 6;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'cron_schedules', array( __CLASS__, 'schedules' ) ); // phpcs:ignore WordPress.WP.CronInterval.CronSchedulesInterval -- One minute, for the background preload only while it has work.
		add_action( 'transara_preload_run', array( __CLASS__, 'run' ) );
		add_action( 'admin_post_transara_preload', array( __CLASS__, 'handle_start' ) );
		add_action( 'transara_settings_saved', array( __CLASS__, 'start' ) );
		add_filter( 'wp_speculation_rules_configuration', array( __CLASS__, 'speculation' ) );
		add_filter( 'wp_speculation_rules_href_exclude_paths', array( __CLASS__, 'speculation_exclude' ) );
	}

	/**
	 * Every minute.
	 *
	 * @param array $s Schedules.
	 * @return array
	 */
	public static function schedules( $s ) {
		$s['transara_minute'] = array(
			'interval' => MINUTE_IN_SECONDS,
			'display'  => did_action( 'init' ) ? __( 'Every minute (Transara)', 'transara' ) : 'Every minute (Transara)',
		);
		return $s;
	}

	/**
	 * Load the next page early (WordPress 6.8+ speculative loading).
	 *
	 * @param array|null $config Config.
	 * @return array|null
	 */
	public static function speculation( $config ) {
		$mode = (string) Transara_Settings::get( 'link_preload' );
		if ( 'off' === $mode ) {
			return null;
		}
		if ( in_array( $mode, array( 'prefetch', 'prerender' ), true ) ) {
			return array(
				'mode'      => $mode,
				'eagerness' => 'moderate',
			);
		}
		return $config;
	}

	/**
	 * Pages that must never be loaded early (logout links, cart actions…).
	 *
	 * @param array $paths Paths.
	 * @return array
	 */
	public static function speculation_exclude( $paths ) {
		$paths[] = '/*\\?*add-to-cart=*';
		$paths[] = '/*\\?*action=*';
		foreach ( Transara_Settings::lines( 'cache_reject_uri' ) as $p ) {
			if ( false === strpbrk( $p, '()[]{}+^$|\\' ) ) {
				$paths[] = str_replace( '.*', '*', $p );
			}
		}
		return $paths;
	}

	/*
	 * Queue.
	 */

	/**
	 * Queue.
	 *
	 * @return string[]
	 */
	private static function queue() {
		$q = get_option( self::QUEUE, array() );
		return is_array( $q ) ? $q : array();
	}

	/**
	 * Save the queue and keep the job scheduled while there is work.
	 *
	 * @param string[] $queue Queue.
	 */
	private static function save( $queue ) {
		$queue = array_values( array_unique( $queue ) );
		update_option( self::QUEUE, $queue, false );
		if ( $queue && ! wp_next_scheduled( 'transara_preload_run' ) ) {
			wp_schedule_event( time() + 30, 'transara_minute', 'transara_preload_run' );
		} elseif ( ! $queue ) {
			wp_clear_scheduled_hook( 'transara_preload_run' );
		}
	}

	/**
	 * Start a full preload from the sitemap.
	 */
	public static function start() {
		if ( ! Transara_Settings::on( 'preload_enabled' ) || ! Transara_Settings::on( 'cache_enabled' ) ) {
			self::save( array() );
			return;
		}
		self::save( array_merge( array( home_url( '/' ) ), self::sitemap_urls() ) );
	}

	/**
	 * Add pages to the front of the queue (pages cleared after an edit).
	 *
	 * @param string[] $urls URLs.
	 */
	public static function add( $urls ) {
		if ( ! Transara_Settings::on( 'preload_enabled' ) || ! Transara_Settings::on( 'cache_enabled' ) ) {
			return;
		}
		self::save( array_merge( array_values( (array) $urls ), self::queue() ) );
	}

	/**
	 * Progress for the dashboard.
	 *
	 * @return array left.
	 */
	public static function progress() {
		return array( 'left' => count( self::queue() ) );
	}

	/**
	 * Visit the next pages.
	 */
	public static function run() {
		$queue = self::queue();
		if ( ! $queue ) {
			self::save( array() );
			return;
		}
		$batch  = array_splice( $queue, 0, self::BATCH );
		$mobile = Transara_Settings::on( 'cache_mobile' );
		self::save( $queue );
		foreach ( $batch as $url ) {
			if ( ! self::allowed( $url ) ) {
				continue;
			}
			self::visit( $url, 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) Transara-Preload/' . TRANSARA_VERSION );
			if ( $mobile ) {
				self::visit( $url, 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Mobile Transara-Preload/' . TRANSARA_VERSION );
			}
		}
	}

	/**
	 * One visit.
	 *
	 * @param string $url URL.
	 * @param string $ua  User agent.
	 */
	private static function visit( $url, $ua ) {
		wp_remote_get(
			$url,
			array(
				'timeout'    => 15,
				'user-agent' => $ua,
				'sslverify'  => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for requests to the own site.
				'headers'    => array( 'X-Transara-Preload' => '1' ),
				'cookies'    => array(),
			)
		);
	}

	/**
	 * Whether a URL may be preloaded.
	 *
	 * @param string $url URL.
	 * @return bool
	 */
	private static function allowed( $url ) {
		if ( wp_parse_url( $url, PHP_URL_HOST ) !== wp_parse_url( home_url(), PHP_URL_HOST ) ) {
			return false;
		}
		$path = (string) wp_parse_url( $url, PHP_URL_PATH );
		foreach ( Transara_Settings::lines( 'preload_exclude' ) as $p ) {
			if ( @preg_match( '#^' . $p . '$#i', $path ) || untrailingslashit( $p ) === untrailingslashit( $url ) ) { // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- Patterns typed by the site owner may be invalid.
				return false;
			}
		}
		return true;
	}

	/*
	 * Sitemaps.
	 */

	/**
	 * Sitemap addresses to read.
	 *
	 * @return string[]
	 */
	private static function sitemaps() {
		$maps = Transara_Settings::lines( 'preload_sitemaps' );
		if ( defined( 'WPSEO_VERSION' ) || class_exists( 'RankMath' ) ) {
			$maps[] = home_url( '/sitemap_index.xml' );
		} elseif ( defined( 'SEOPRESS_VERSION' ) ) {
			$maps[] = home_url( '/sitemaps.xml' );
		} else {
			$maps[] = home_url( '/wp-sitemap.xml' );
		}
		/**
		 * Sitemaps used by the preload.
		 *
		 * @param string[] $maps URLs.
		 */
		return array_values( array_unique( (array) apply_filters( 'transara_preload_sitemaps', $maps ) ) );
	}

	/**
	 * Page URLs from the sitemaps (sitemap indexes are followed).
	 *
	 * @return string[]
	 */
	public static function sitemap_urls() {
		/**
		 * Most pages to preload.
		 *
		 * @param int $max Pages.
		 */
		$max   = (int) apply_filters( 'transara_preload_max', 3000 );
		$todo  = self::sitemaps();
		$seen  = array();
		$pages = array();
		while ( $todo && count( $seen ) < 60 && count( $pages ) < $max ) {
			$map = array_shift( $todo );
			if ( isset( $seen[ $map ] ) ) {
				continue;
			}
			$seen[ $map ] = true;
			$res          = wp_remote_get(
				$map,
				array(
					'timeout'   => 20,
					'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for requests to the own site.
				)
			);
			if ( is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ) {
				continue;
			}
			$xml = (string) wp_remote_retrieve_body( $res );
			if ( ! preg_match_all( '#<loc>\s*(?:<!\[CDATA\[)?\s*([^<\]\s]+)#i', $xml, $m ) ) {
				continue;
			}
			$is_index = false !== stripos( $xml, '<sitemapindex' );
			foreach ( $m[1] as $loc ) {
				$loc = esc_url_raw( html_entity_decode( $loc, ENT_QUOTES | ENT_XML1 ) );
				if ( $is_index ) {
					$todo[] = $loc;
				} elseif ( self::allowed( $loc ) ) {
					$pages[] = $loc;
				}
			}
		}
		return array_slice( array_values( array_unique( $pages ) ), 0, $max );
	}

	/**
	 * Admin action: preload now.
	 */
	public static function handle_start() {
		check_admin_referer( 'transara_preload' );
		if ( ! current_user_can( Transara_Admin::cap() ) ) {
			wp_die( esc_html__( 'You are not allowed to do this.', 'transara' ), 403 );
		}
		self::start();
		Transara_Admin::flash( sprintf( /* translators: %d: number of pages */ __( 'Preload started: %d pages are cached in the background, a few every minute.', 'transara' ), count( self::queue() ) ) );
		Transara_Admin::back();
	}
}
