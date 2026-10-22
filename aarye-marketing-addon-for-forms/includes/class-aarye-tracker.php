<?php
/**
 * Loads the visitor tracker and reads what it remembered when a form is submitted.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Tracker.
 */
class AARYE_Tracker {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ), 1 );
	}

	/**
	 * Add the tracker to every front-end page.
	 */
	public static function enqueue() {
		wp_enqueue_script( 'aarye-tracker', AARYE_URL . 'assets/js/tracker.js', array(), aarye_asset_version( 'assets/js/tracker.js' ), array( 'in_footer' => false, 'strategy' => 'defer' ) );
		wp_add_inline_script(
			'aarye-tracker',
			'window.aaryeTrack=' . wp_json_encode(
				array(
					'params'  => AARYE_Sources::click_params(),
					'days'    => (int) AARYE_Settings::get( 'cookie_days' ),
					'consent' => AARYE_Settings::on( 'wait_for_consent' ),
				)
			) . ';',
			'before'
		);
	}

	/**
	 * Clean one remembered visit from the cookie.
	 *
	 * @param mixed $touch Raw value.
	 * @return array|null
	 */
	private static function clean_touch( $touch ) {
		if ( ! is_array( $touch ) ) {
			return null;
		}
		$params = array();
		if ( isset( $touch['p'] ) && is_array( $touch['p'] ) ) {
			foreach ( array_slice( $touch['p'], 0, 40, true ) as $key => $value ) {
				$key = sanitize_key( $key );
				if ( '' !== $key && is_scalar( $value ) ) {
					$params[ $key ] = mb_substr( sanitize_text_field( (string) $value ), 0, 200 );
				}
			}
		}
		$path = isset( $touch['u'] ) ? (string) $touch['u'] : '';
		return array(
			'time'   => isset( $touch['t'] ) ? absint( $touch['t'] ) : 0,
			'url'    => '' !== $path && '/' === $path[0] ? esc_url_raw( home_url( $path ) ) : '',
			'ref'    => isset( $touch['r'] ) ? esc_url_raw( (string) $touch['r'] ) : '',
			'params' => $params,
		);
	}

	/**
	 * What the tracker remembered about the current visitor.
	 *
	 * @return array{first: array|null, last: array|null, visits: int, page: string, lang: string, visitor: string}
	 */
	public static function read() {
		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- JSON, every field is cleaned below.
		$raw  = isset( $_COOKIE['aarye_attr'] ) ? wp_unslash( $_COOKIE['aarye_attr'] ) : '';
		$data = json_decode( (string) $raw, true );
		$data = is_array( $data ) ? $data : array();
		$page = isset( $data['pg'] ) ? (string) $data['pg'] : '';
		return array(
			'first'   => self::clean_touch( isset( $data['f'] ) ? $data['f'] : null ),
			'last'    => self::clean_touch( isset( $data['l'] ) ? $data['l'] : null ),
			'visits'  => isset( $data['n'] ) ? absint( $data['n'] ) : 0,
			'page'    => '' !== $page && '/' === $page[0] ? esc_url_raw( home_url( $page ) ) : '',
			'lang'    => isset( $data['lg'] ) ? sanitize_text_field( (string) $data['lg'] ) : '',
			'visitor' => isset( $_COOKIE['aarye_vid'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( sanitize_text_field( wp_unslash( $_COOKIE['aarye_vid'] ) ) ) ) : '',
		);
	}

	/**
	 * Language of the submission: the multilingual plugin's current language, else the page's.
	 *
	 * @param string $page_lang Language the tracker saw on the page (html lang).
	 * @return string e.g. "en", "ar", "fr".
	 */
	public static function language( $page_lang = '' ) {
		$lang = '';
		if ( has_filter( 'wpml_current_language' ) ) {
			$lang = (string) apply_filters( 'wpml_current_language', null ); // phpcs:ignore WordPress.NamingConventions.PrefixAllGlobals.NonPrefixedHooknameFound -- WPML's own filter.
		}
		if ( '' === $lang && function_exists( 'pll_current_language' ) ) {
			$lang = (string) pll_current_language(); // Polylang.
		}
		if ( '' === $lang && ! empty( $GLOBALS['TRP_LANGUAGE'] ) ) {
			$lang = (string) $GLOBALS['TRP_LANGUAGE']; // TranslatePress.
		}
		if ( '' === $lang && function_exists( 'weglot_get_current_language' ) ) {
			$lang = (string) weglot_get_current_language(); // Weglot.
		}
		if ( '' === $lang ) {
			$lang = '' !== $page_lang ? $page_lang : get_locale();
		}
		$lang = strtolower( str_replace( '_', '-', $lang ) );
		return substr( preg_replace( '/[^a-z0-9-]/', '', $lang ), 0, 12 );
	}

	/**
	 * Device type from the browser's user agent.
	 *
	 * @param string $ua User agent.
	 * @return string mobile, tablet or desktop.
	 */
	public static function device( $ua ) {
		$ua = strtolower( $ua );
		if ( preg_match( '/ipad|tablet|kindle|silk|(android(?!.*mobile))/', $ua ) ) {
			return 'tablet';
		}
		if ( preg_match( '/mobi|iphone|ipod|android|blackberry|opera mini|iemobile/', $ua ) ) {
			return 'mobile';
		}
		return 'desktop';
	}

	/**
	 * Browser name from the user agent.
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	public static function browser( $ua ) {
		$map = array(
			'Edg/'     => 'Edge',
			'OPR/'     => 'Opera',
			'SamsungB' => 'Samsung Internet',
			'Firefox/' => 'Firefox',
			'CriOS'    => 'Chrome',
			'Chrome/'  => 'Chrome',
			'FBAN'     => 'Facebook app',
			'FBAV'     => 'Facebook app',
			'Instagram' => 'Instagram app',
			'Safari/'  => 'Safari',
		);
		foreach ( $map as $needle => $name ) {
			if ( false !== stripos( $ua, $needle ) ) {
				return $name;
			}
		}
		return '' !== $ua ? __( 'Other', 'aarye-marketing-addon-for-forms' ) : '';
	}

	/**
	 * Whether the request looks like a bot (crawler, script) rather than a person.
	 *
	 * @param string $ua User agent.
	 * @return bool
	 */
	public static function is_bot( $ua ) {
		if ( '' === trim( $ua ) ) {
			return true;
		}
		return (bool) preg_match( '/bot|crawl|spider|slurp|curl|wget|python|java\/|go-http|headless|phantom|httpclient|axios|okhttp|scrapy/i', $ua );
	}
}
