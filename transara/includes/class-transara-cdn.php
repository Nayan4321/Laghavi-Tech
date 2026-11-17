<?php
/**
 * CDN and other caches: serve files from a CDN address, and clear Cloudflare and Varnish whenever
 * Transara clears its own cache.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * CDN.
 */
class Transara_Cdn {

	/**
	 * URLs to clear in Cloudflare at the end of the request.
	 *
	 * @var string[]
	 */
	private static $cf_urls = array();

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_schema', array( __CLASS__, 'schema' ) );
		add_filter( 'transara_buffer', array( __CLASS__, 'rewrite' ), 90 );
		add_action( 'transara_purged_all', array( __CLASS__, 'purge_all' ) );
		add_action( 'transara_purged_url', array( __CLASS__, 'purge_url' ) );
		add_action( 'shutdown', array( __CLASS__, 'cloudflare_flush' ) );
		add_action( 'transara_section_after', array( __CLASS__, 'cloudflare_status' ) );
	}

	/**
	 * Settings.
	 *
	 * @param array $schema Schema.
	 * @return array
	 */
	public static function schema( $schema ) {
		return Transara_Settings::insert_sections(
			$schema,
			'speed',
			array(
				'cdn' => array(
					'title'       => __( 'CDN, Cloudflare and Varnish', 'transara' ),
					'description' => __( 'Serve files from a CDN, and clear Cloudflare and Varnish together with Transara\'s cache.', 'transara' ),
					'fields'      => array(
						'cdn_enabled'   => array(
							'type'    => 'checkbox',
							'label'   => __( 'Serve files from a CDN', 'transara' ),
							'default' => 0,
						),
						'cdn_url'       => array(
							'type'        => 'text',
							'label'       => __( 'CDN address', 'transara' ),
							'description' => __( 'For example https://cdn.example.com (a pull zone pointing to this site).', 'transara' ),
							'default'     => '',
						),
						'cdn_types'     => array(
							'type'    => 'select',
							'label'   => __( 'Files to serve from the CDN', 'transara' ),
							'options' => array(
								'all'    => __( 'Images, CSS, JavaScript and fonts', 'transara' ),
								'images' => __( 'Images only', 'transara' ),
							),
							'default' => 'all',
						),
						'cdn_exclude'   => array(
							'type'        => 'lines',
							'label'       => __( 'Files not to serve from the CDN', 'transara' ),
							'default'     => array(),
						),
						'cf_token'      => array(
							'type'        => 'secret',
							'label'       => __( 'Cloudflare API token', 'transara' ),
							'description' => __( 'Cloudflare > My Profile > API Tokens > Create token, with the permission Zone > Cache Purge > Purge. Leave empty if you don\'t use Cloudflare.', 'transara' ),
							'default'     => '',
						),
						'cf_zone'       => array(
							'type'        => 'text',
							'label'       => __( 'Cloudflare zone ID', 'transara' ),
							'description' => __( 'On the Overview page of your domain in Cloudflare.', 'transara' ),
							'default'     => '',
						),
						'varnish_purge' => array(
							'type'        => 'checkbox',
							'label'       => __( 'Clear Varnish too', 'transara' ),
							'description' => __( 'Only if your host uses Varnish. Transara sends PURGE requests to your own server.', 'transara' ),
							'default'     => 0,
						),
					),
				),
			),
			'preload'
		);
	}

	/**
	 * Point file URLs at the CDN.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function rewrite( $html ) {
		$cdn = untrailingslashit( esc_url_raw( (string) Transara_Settings::get( 'cdn_url' ) ) );
		if ( ! Transara_Settings::on( 'cdn_enabled' ) || '' === $cdn ) {
			return $html;
		}
		$site    = untrailingslashit( home_url() );
		$host    = (string) wp_parse_url( $site, PHP_URL_HOST ) . ( wp_parse_url( $site, PHP_URL_PORT ) ? ':' . wp_parse_url( $site, PHP_URL_PORT ) : '' );
		$ext     = 'images' === Transara_Settings::get( 'cdn_types' ) ? 'jpe?g|png|gif|webp|avif|svg|ico' : 'jpe?g|png|gif|webp|avif|svg|ico|css|js|woff2?|ttf|otf|eot|mp4|webm';
		$exclude = Transara_Settings::lines( 'cdn_exclude' );
		$pattern = '#(?:https?:)?//' . preg_quote( $host, '#' ) . '(/(?:[^"\'\s()<>]*/)?(?:wp-content|wp-includes)/[^"\'\s()<>?]+\.(?:' . $ext . '))(\?[^"\'\s()<>]*)?#i';
		return (string) preg_replace_callback(
			$pattern,
			static function ( $m ) use ( $cdn, $exclude ) {
				if ( Transara_Html::matches( $m[0], $exclude ) ) {
					return $m[0];
				}
				return $cdn . $m[1] . ( isset( $m[2] ) ? $m[2] : '' );
			},
			$html
		);
	}

	/*
	 * Cloudflare.
	 */

	/**
	 * Whether Cloudflare clearing is set up.
	 *
	 * @return bool
	 */
	private static function cloudflare_on() {
		return '' !== (string) Transara_Settings::get( 'cf_token' ) && '' !== (string) Transara_Settings::get( 'cf_zone' );
	}

	/**
	 * Call Cloudflare's cache purge.
	 *
	 * @param array $body Request body.
	 * @return true|WP_Error
	 */
	private static function cloudflare( $body ) {
		$zone = preg_replace( '/[^a-f0-9]/i', '', (string) Transara_Settings::get( 'cf_zone' ) );
		$res  = wp_remote_post(
			'https://api.cloudflare.com/client/v4/zones/' . $zone . '/purge_cache',
			array(
				'timeout' => 10,
				'headers' => array(
					'Authorization' => 'Bearer ' . Transara_Settings::get( 'cf_token' ),
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$data = json_decode( (string) wp_remote_retrieve_body( $res ), true );
		if ( empty( $data['success'] ) ) {
			$msg = isset( $data['errors'][0]['message'] ) ? (string) $data['errors'][0]['message'] : __( 'Cloudflare refused the request.', 'transara' );
			update_option( 'transara_cf_error', $msg, false );
			return new WP_Error( 'transara_cf', $msg );
		}
		delete_option( 'transara_cf_error' );
		update_option( 'transara_cf_last', time(), false );
		return true;
	}

	/**
	 * Everything cleared: Cloudflare and Varnish too.
	 */
	public static function purge_all() {
		if ( self::cloudflare_on() ) {
			self::cloudflare( array( 'purge_everything' => true ) );
		}
		if ( Transara_Settings::on( 'varnish_purge' ) ) {
			self::varnish( home_url( '/' ), true );
		}
	}

	/**
	 * One page cleared.
	 *
	 * @param string $url URL.
	 */
	public static function purge_url( $url ) {
		if ( self::cloudflare_on() ) {
			self::$cf_urls[] = $url;
		}
		if ( Transara_Settings::on( 'varnish_purge' ) ) {
			self::varnish( $url, false );
		}
	}

	/**
	 * Send the collected pages to Cloudflare (at most 30 per request, as Cloudflare allows).
	 */
	public static function cloudflare_flush() {
		if ( ! self::$cf_urls ) {
			return;
		}
		foreach ( array_chunk( array_values( array_unique( self::$cf_urls ) ), 30 ) as $chunk ) {
			self::cloudflare( array( 'files' => $chunk ) );
		}
		self::$cf_urls = array();
	}

	/**
	 * Cloudflare status under the CDN section.
	 *
	 * @param string $section Section.
	 */
	public static function cloudflare_status( $section ) {
		if ( 'cdn' !== $section || ! self::cloudflare_on() ) {
			return;
		}
		$err  = (string) get_option( 'transara_cf_error' );
		$last = (int) get_option( 'transara_cf_last' );
		if ( '' !== $err ) {
			echo '<p class="transara-warn">' . esc_html( sprintf( /* translators: %s: error */ __( 'Cloudflare: %s', 'transara' ), $err ) ) . '</p>';
		} elseif ( $last ) {
			echo '<p class="description">' . esc_html( sprintf( /* translators: %s: time ago */ __( 'Cloudflare was last cleared %s ago.', 'transara' ), human_time_diff( $last ) ) ) . '</p>';
		}
	}

	/**
	 * Varnish PURGE to this server.
	 *
	 * @param string $url   URL.
	 * @param bool   $regex Clear everything below it.
	 */
	private static function varnish( $url, $regex ) {
		$parts = wp_parse_url( $url );
		if ( empty( $parts['host'] ) ) {
			return;
		}
		/**
		 * Address of the Varnish server (default: this site's own address).
		 *
		 * @param string $ip IP or host ('' = the site).
		 */
		$ip     = (string) apply_filters( 'transara_varnish_ip', '' );
		$target = ( isset( $parts['scheme'] ) ? $parts['scheme'] : 'http' ) . '://' . ( '' !== $ip ? $ip : $parts['host'] ) . ( isset( $parts['path'] ) ? $parts['path'] : '/' ) . ( $regex ? '.*' : '' );
		wp_remote_request(
			$target,
			array(
				'method'    => 'PURGE',
				'blocking'  => false,
				'timeout'   => 2,
				'sslverify' => apply_filters( 'https_local_ssl_verify', false ), // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- Core filter for requests to the own site.
				'headers'   => array(
					'Host'           => $parts['host'],
					'X-Purge-Method' => $regex ? 'regex' : 'default',
				),
			)
		);
	}
}
