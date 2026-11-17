<?php
/**
 * Fonts and connections: Google Fonts copied into the site (fewer connections, no visitor data sent to
 * Google), text shown while fonts load, early connections to other domains and preloaded fonts.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Fonts.
 */
class Transara_Fonts {

	const MAP = 'transara_google_fonts';

	/**
	 * Hook up.
	 */
	public static function init() {
		add_filter( 'transara_schema', array( __CLASS__, 'schema' ) );
		add_filter( 'transara_buffer', array( __CLASS__, 'process' ), 10 );
		add_filter( 'wp_resource_hints', array( __CLASS__, 'hints' ), 10, 2 );
		add_action( 'wp_head', array( __CLASS__, 'preload' ), 2 );
		add_action( 'transara_purged_all', array( __CLASS__, 'forget_missing' ) );
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
				'fonts' => array(
					'title'       => __( 'Fonts and connections', 'transara' ),
					'description' => __( 'Fonts from your own site load faster and send no visitor data to Google.', 'transara' ),
					'fields'      => array(
						'google_fonts_local' => array(
							'type'        => 'checkbox',
							'label'       => __( 'Host Google Fonts on your site', 'transara' ),
							'description' => __( 'Each Google Fonts file your pages use is copied once into wp-content/uploads/transara-fonts.', 'transara' ),
							'default'     => 0,
						),
						'font_display'       => array(
							'type'        => 'checkbox',
							'label'       => __( 'Show text while Google Fonts load', 'transara' ),
							'description' => __( 'Adds display=swap, so text never stays invisible.', 'transara' ),
							'default'     => 1,
						),
						'preconnect'         => array(
							'type'        => 'lines',
							'label'       => __( 'Connect early to these domains', 'transara' ),
							'description' => __( 'One address per line, for example https://cdn.example.com.', 'transara' ),
							'default'     => array(),
						),
						'preload_fonts'      => array(
							'type'        => 'lines',
							'label'       => __( 'Preload these font files', 'transara' ),
							'description' => __( 'Full addresses of .woff2 files used at the top of every page, one per line.', 'transara' ),
							'default'     => array(),
						),
					),
				),
			),
			'preload'
		);
	}

	/**
	 * Early connections.
	 *
	 * @param array  $urls Hints.
	 * @param string $type Hint type.
	 * @return array
	 */
	public static function hints( $urls, $type ) {
		if ( 'preconnect' === $type ) {
			foreach ( Transara_Settings::lines( 'preconnect' ) as $url ) {
				$urls[] = array(
					'href' => esc_url_raw( $url ),
					'crossorigin',
				);
			}
		}
		if ( Transara_Settings::on( 'google_fonts_local' ) && in_array( $type, array( 'preconnect', 'dns-prefetch' ), true ) ) {
			$urls = array_values(
				array_filter(
					$urls,
					static function ( $u ) {
						$href = is_array( $u ) ? ( isset( $u['href'] ) ? $u['href'] : '' ) : $u;
						return false === strpos( (string) $href, 'fonts.googleapis.com' ) && false === strpos( (string) $href, 'fonts.gstatic.com' );
					}
				)
			);
		}
		return $urls;
	}

	/**
	 * Preloaded fonts.
	 */
	public static function preload() {
		foreach ( Transara_Settings::lines( 'preload_fonts' ) as $url ) {
			$ext  = strtolower( pathinfo( (string) wp_parse_url( $url, PHP_URL_PATH ), PATHINFO_EXTENSION ) );
			$type = in_array( $ext, array( 'woff2', 'woff', 'ttf', 'otf' ), true ) ? 'font/' . $ext : 'font/woff2';
			echo '<link rel="preload" href="' . esc_url( $url ) . '" as="font" type="' . esc_attr( $type ) . '" crossorigin />' . "\n";
		}
	}

	/**
	 * Google Fonts links in the page.
	 *
	 * @param string $html Page.
	 * @return string
	 */
	public static function process( $html ) {
		$local = Transara_Settings::on( 'google_fonts_local' );
		$swap  = Transara_Settings::on( 'font_display' );
		if ( ( ! $local && ! $swap ) || false === strpos( $html, 'fonts.googleapis.com' ) ) {
			return $html;
		}
		$fetched = 0;
		return (string) preg_replace_callback(
			'#<link\b[^>]*fonts\.googleapis\.com/css[^>]*>#i',
			static function ( $m ) use ( $local, $swap, &$fetched ) {
				$tag  = $m[0];
				$href = html_entity_decode( (string) Transara_Html::attr( $tag, 'href' ), ENT_QUOTES );
				if ( 0 === strpos( $href, '//' ) ) {
					$href = 'https:' . $href;
				}
				if ( $swap && false === strpos( $href, 'display=' ) ) {
					$href = add_query_arg( 'display', 'swap', $href );
				}
				if ( $local ) {
					// Copy at most one stylesheet per page view, so a first visit is never slowed much.
					$copy = self::local_copy( $href, $fetched < 1 );
					if ( '' !== $copy ) {
						return Transara_Html::remove( Transara_Html::set( $tag, 'href', $copy ), 'crossorigin' );
					}
					++$fetched;
				}
				return Transara_Html::set( $tag, 'href', $href );
			},
			$html
		);
	}

	/**
	 * Folder for copied fonts.
	 *
	 * @return array dir, url.
	 */
	private static function location() {
		$up = wp_upload_dir( null, false );
		return array( $up['basedir'] . '/transara-fonts', set_url_scheme( $up['baseurl'] . '/transara-fonts' ) );
	}

	/**
	 * Local copy of a Google Fonts stylesheet (made now when $make is true).
	 *
	 * @param string $href Google Fonts address.
	 * @param bool   $make Download now if missing.
	 * @return string Local URL or ''.
	 */
	public static function local_copy( $href, $make ) {
		list( $dir, $url ) = self::location();
		$key               = substr( md5( $href ), 0, 12 );
		$map               = get_option( self::MAP, array() );
		$map               = is_array( $map ) ? $map : array();
		if ( isset( $map[ $key ] ) && is_file( $dir . '/' . $key . '/fonts.css' ) ) {
			return $url . '/' . $key . '/fonts.css';
		}
		if ( ! $make || get_transient( 'transara_gf_fail_' . $key ) ) {
			return '';
		}
		$res = wp_remote_get(
			$href,
			array(
				'timeout'    => 10,
				// A current browser's name, so Google sends woff2 files.
				'user-agent' => 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0 Safari/537.36',
			)
		);
		$css = is_wp_error( $res ) || 200 !== (int) wp_remote_retrieve_response_code( $res ) ? '' : (string) wp_remote_retrieve_body( $res );
		if ( '' === $css || ! preg_match_all( '#url\((https://fonts\.gstatic\.com/[^)]+)\)#', $css, $files ) ) {
			set_transient( 'transara_gf_fail_' . $key, 1, HOUR_IN_SECONDS );
			return '';
		}
		if ( ! wp_mkdir_p( $dir . '/' . $key ) ) {
			return '';
		}
		require_once ABSPATH . 'wp-admin/includes/file.php';
		$fs = Transara_Cache::fs();
		foreach ( array_unique( $files[1] ) as $i => $remote ) {
			$name = $i . '-' . sanitize_file_name( basename( (string) wp_parse_url( $remote, PHP_URL_PATH ) ) );
			$tmp  = download_url( $remote, 15 );
			if ( is_wp_error( $tmp ) ) {
				set_transient( 'transara_gf_fail_' . $key, 1, HOUR_IN_SECONDS );
				return '';
			}
			$fs->move( $tmp, $dir . '/' . $key . '/' . $name, true );
			$css = str_replace( $remote, $name, $css );
		}
		$fs->put_contents( $dir . '/' . $key . '/fonts.css', "/* Google Fonts copied by Transara from " . esc_url_raw( $href ) . " */\n" . $css );
		$map[ $key ] = $href;
		update_option( self::MAP, $map, false );
		return $url . '/' . $key . '/fonts.css';
	}

	/**
	 * Forget copies whose files were removed.
	 */
	public static function forget_missing() {
		$map = get_option( self::MAP, array() );
		if ( ! is_array( $map ) ) {
			return;
		}
		list( $dir ) = self::location();
		foreach ( array_keys( $map ) as $key ) {
			if ( ! is_file( $dir . '/' . $key . '/fonts.css' ) ) {
				unset( $map[ $key ] );
			}
		}
		update_option( self::MAP, $map, false );
	}
}
