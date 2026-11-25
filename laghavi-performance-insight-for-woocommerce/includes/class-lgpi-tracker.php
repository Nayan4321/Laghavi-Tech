<?php
/**
 * Counts page views and visitors without cookies.
 *
 * A tiny script sends one request per page view to this site's own REST API (works with page caching).
 * Nothing is sent to any other website. Logged-in staff, bots and (optionally) "Do Not Track" browsers are not counted.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front-end counter.
 */
class LGPI_Tracker {

	const BOTS = '/bot|crawl|spider|slurp|bingpreview|facebookexternalhit|embedly|quora link preview|whatsapp|telegrambot|preview|headless|lighthouse|pingdom|uptime|monitor|curl|wget|python|java\/|httpclient|scrapy|semrush|ahrefs|mj12|dotbot|petal|yandex|baiduspider|gtmetrix|chrome-lighthouse/i';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'woocommerce_add_to_cart', array( __CLASS__, 'added_to_cart' ), 10, 2 );
	}

	/**
	 * Should the current visitor be counted at all?
	 *
	 * @return bool
	 */
	private static function counts_user() {
		if ( is_admin() || is_preview() || is_customize_preview() || is_feed() || is_robots() ) {
			return false;
		}
		$user = wp_get_current_user();
		if ( $user && $user->exists() ) {
			$skip = (array) LGPI_Plugin::settings()['exclude_roles'];
			if ( array_intersect( $skip, (array) $user->roles ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * What is this page? [type, id]
	 *
	 * @return array
	 */
	public static function current_object() {
		if ( is_front_page() ) {
			$front = (int) get_option( 'page_on_front' );
			return $front ? array( 'page', $front ) : array( 'home', 0 );
		}
		if ( is_home() ) {
			$blog = (int) get_option( 'page_for_posts' );
			return $blog ? array( 'page', $blog ) : array( 'home', 0 );
		}
		if ( function_exists( 'is_shop' ) && is_shop() ) {
			return array( 'shop', (int) wc_get_page_id( 'shop' ) );
		}
		if ( is_singular() ) {
			$p = get_queried_object();
			return array( substr( (string) $p->post_type, 0, 20 ), (int) $p->ID );
		}
		if ( is_category() || is_tag() || is_tax() ) {
			$t = get_queried_object();
			return array( substr( (string) $t->taxonomy, 0, 20 ), (int) $t->term_id );
		}
		if ( is_search() ) {
			return array( 'search', 0 );
		}
		return array( 'archive', 0 );
	}

	/**
	 * Add the counter script.
	 */
	public static function enqueue() {
		if ( ! self::counts_user() ) {
			return;
		}
		list( $type, $id ) = is_404() ? array( 'notfound', 0 ) : self::current_object();
		wp_enqueue_script(
			'lgpi-track',
			LGPI_URL . 'assets/js/track.js',
			array(),
			LGPI_VERSION,
			array(
				'in_footer' => true,
				'strategy'  => 'defer',
			)
		);
		wp_localize_script(
			'lgpi-track',
			'lgpiTrack',
			array(
				'url' => esc_url_raw( rest_url( 'lgpi/v1/hit' ) ),
				't'   => $type,
				'i'   => $id,
				'dnt' => (int) LGPI_Plugin::settings()['respect_dnt'],
				'co'    => (int) ( function_exists( 'is_checkout' ) && is_checkout() && ! is_wc_endpoint_url( 'order-received' ) ),
				'leave' => esc_url_raw( rest_url( 'lgpi/v1/leave' ) ),
				's'     => is_search() ? mb_substr( get_search_query( false ), 0, 100 ) : '',
				'nf'    => is_404() ? self::request_path() : '',
			)
		);
	}

	/**
	 * REST endpoint for the counter.
	 */
	public static function routes() {
		register_rest_route(
			'lgpi/v1',
			'/hit',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'hit' ),
				'permission_callback' => '__return_true', // Public by design: it only counts a page view.
			)
		);
		register_rest_route(
			'lgpi/v1',
			'/leave',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'leave' ),
				'permission_callback' => '__return_true', // Public by design: it only adds the seconds a page was open.
			)
		);
	}

	/**
	 * Path of the current request without the query string (for "page not found" reports).
	 *
	 * @return string
	 */
	private static function request_path() {
		$uri = isset( $_SERVER['REQUEST_URI'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REQUEST_URI'] ) ) : '';
		return mb_substr( (string) wp_parse_url( $uri, PHP_URL_PATH ), 0, 190 );
	}

	/**
	 * Visitor's IP address (only used to skip excluded addresses and for the daily anonymous hash; never stored).
	 *
	 * @return string
	 */
	private static function ip() {
		$ip = isset( $_SERVER['REMOTE_ADDR'] ) ? sanitize_text_field( wp_unslash( $_SERVER['REMOTE_ADDR'] ) ) : '';
		foreach ( array( 'HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR' ) as $h ) {
			if ( ! empty( $_SERVER[ $h ] ) ) {
				$ip = trim( explode( ',', sanitize_text_field( wp_unslash( $_SERVER[ $h ] ) ) )[0] );
				break;
			}
		}
		return $ip;
	}

	/**
	 * Is this IP address in the "don't count" list? Entries are full addresses or end with * (e.g. 192.168.*).
	 *
	 * @param string $ip IP address.
	 * @return bool
	 */
	public static function ip_excluded( $ip ) {
		foreach ( (array) LGPI_Plugin::settings()['exclude_ips'] as $rule ) {
			$rule = trim( (string) $rule );
			if ( '' === $rule ) {
				continue;
			}
			if ( substr( $rule, -1 ) === '*' ? 0 === strpos( $ip, rtrim( $rule, '*' ) ) : $ip === $rule ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Seconds a page was open, sent when the visitor leaves it.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function leave( $request ) {
		$body = json_decode( (string) $request->get_body(), true );
		$pv   = is_array( $body ) && isset( $body['pv'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $body['pv'] ) ) : '';
		if ( '' !== $pv && strlen( $pv ) <= 12 && isset( $body['d'] ) ) {
			LGPI_DB::set_duration( $pv, absint( $body['d'] ) );
		}
		return new WP_REST_Response( null, 204 );
	}

	/**
	 * Count one view.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function hit( $request ) {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		if ( '' === $ua || preg_match( self::BOTS, $ua ) || self::ip_excluded( self::ip() ) ) {
			return new WP_REST_Response( null, 204 );
		}
		$body = json_decode( (string) $request->get_body(), true );
		$body = is_array( $body ) ? $body : $request->get_params();
		$type = sanitize_key( isset( $body['t'] ) ? (string) $body['t'] : '' );
		$id   = isset( $body['i'] ) ? absint( $body['i'] ) : 0;
		if ( '' === $type || strlen( $type ) > 20 ) {
			return new WP_REST_Response( null, 204 );
		}
		if ( 'notfound' === $type ) {
			// Missing pages are only listed (with how often they were asked for), not counted as page views.
			$path = isset( $body['nf'] ) ? mb_substr( sanitize_text_field( (string) $body['nf'] ), 0, 190 ) : '';
			if ( '' !== $path && '/' === $path[0] ) {
				LGPI_DB::add_dims( array( array( 'notfound', $path ) ), false );
			}
			return new WP_REST_Response( null, 204 );
		}
		if ( $id && ! in_array( $type, array( 'home', 'search', 'archive' ), true ) ) {
			// Only count real, published objects (stops junk rows).
			if ( 'shop' === $type ) {
				$ok = function_exists( 'wc_get_page_id' ) && (int) wc_get_page_id( 'shop' ) === $id;
			} else {
				$ok = taxonomy_exists( $type ) ? (bool) get_term( $id, $type ) : ( 'publish' === get_post_status( $id ) && get_post_type( $id ) === $type );
			}
			if ( ! $ok ) {
				return new WP_REST_Response( null, 204 );
			}
		}
		$source   = self::source( isset( $body['r'] ) ? (string) $body['r'] : '', isset( $body['u'] ) ? (string) $body['u'] : '' );
		$device   = self::device( isset( $body['w'] ) ? (int) $body['w'] : 0, $ua );
		$fp       = self::fingerprint( $ua );
		$new_site = LGPI_DB::first_seen( md5( $fp . '|site' ) );
		LGPI_DB::add_view( $type, $id, LGPI_DB::first_seen( md5( $fp . '|' . $type . '|' . $id ) ), $source, $device );
		LGPI_DB::add_view( 'site', 0, $new_site, $source, $device );

		$referrer = isset( $body['r'] ) ? (string) $body['r'] : '';
		$query    = isset( $body['u'] ) ? (string) $body['u'] : '';
		$tz       = isset( $body['z'] ) ? sanitize_text_field( (string) $body['z'] ) : '';
		$cc       = self::country( $tz );
		$host     = self::referrer_host( $referrer );
		$pairs    = array(
			array( 'country', $cc ),
			array( 'browser', self::browser( $ua ) ),
			array( 'os', self::os( $ua ) ),
			array( 'lang', self::language( isset( $body['l'] ) ? (string) $body['l'] : '' ) ),
		);
		$w = isset( $body['w'] ) ? absint( $body['w'] ) : 0;
		$h = isset( $body['h'] ) ? absint( $body['h'] ) : 0;
		if ( $w > 0 && $h > 0 && $w < 10000 && $h < 10000 ) {
			$pairs[] = array( 'screen', $w . '×' . $h );
		}
		if ( $new_site ) {
			// Where the visit came from is counted once per visitor and day (on their first page).
			if ( '' !== $host ) {
				// AI first: gemini.google.com is an assistant, not Google search.
				$ai     = self::ai( $host );
				$engine = $ai ? null : self::engine( $host );
				$social = $ai || $engine ? null : self::social( $host );
				if ( $ai ) {
					$pairs[] = array( 'ai', $ai );
				} elseif ( $engine ) {
					$pairs[] = array( 'engine', $engine );
				} elseif ( $social ) {
					$pairs[] = array( 'social', $social );
				} else {
					$pairs[] = array( 'site', $host );
				}
			}
			parse_str( ltrim( $query, '?' ), $q );
			$utm_source = isset( $q['utm_source'] ) && is_string( $q['utm_source'] ) ? sanitize_text_field( $q['utm_source'] ) : '';
			$utm_medium = isset( $q['utm_medium'] ) && is_string( $q['utm_medium'] ) ? sanitize_text_field( $q['utm_medium'] ) : '';
			if ( isset( $q['utm_campaign'] ) && is_string( $q['utm_campaign'] ) ) {
				$pairs[] = array( 'campaign', self::lower( sanitize_text_field( $q['utm_campaign'] ) ) );
			}
			if ( '' !== $utm_source || '' !== $utm_medium ) {
				$pairs[] = array( 'utm', self::lower( ( '' !== $utm_source ? $utm_source : '(none)' ) . ' / ' . ( '' !== $utm_medium ? $utm_medium : '(none)' ) ) );
			}
		}
		if ( 'search' === $type && ! empty( $body['s'] ) ) {
			$pairs[] = array( 'search', self::lower( mb_substr( sanitize_text_field( (string) $body['s'] ), 0, 100 ) ) );
		}
		LGPI_DB::add_dims( $pairs, (bool) $new_site );
		LGPI_DB::add_hit(
			array(
				'v'   => substr( md5( $fp . '|v' ), 0, 16 ),
				'pv'  => isset( $body['pv'] ) ? preg_replace( '/[^a-z0-9]/', '', strtolower( (string) $body['pv'] ) ) : '',
				'obj' => $type . ':' . $id,
				'src' => $source,
				'dev' => $device,
				'cc'  => $cc,
				'ref' => $host,
			)
		);

		/**
		 * After a view is counted. Lets add-ons count more (for example per country from the browser time zone).
		 *
		 * @param array $hit type, id, new_site, source, referrer, tz, checkout, visitor (anonymous daily hash), device.
		 */
		do_action(
			'lgpi_hit',
			array(
				'type'     => $type,
				'id'       => $id,
				'new_site' => (bool) $new_site,
				'source'   => $source,
				'referrer' => isset( $body['r'] ) ? esc_url_raw( (string) $body['r'] ) : '',
				'tz'       => isset( $body['z'] ) ? sanitize_text_field( (string) $body['z'] ) : '',
				'checkout' => ! empty( $body['co'] ),
				'visitor'  => $fp,
				'device'   => $device,
			)
		);

		return new WP_REST_Response( null, 204 );
	}

	/**
	 * The anonymous key of the current visitor's page views today (same value the counter stores).
	 *
	 * @return string 16 characters.
	 */
	public static function visitor_key() {
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		return substr( md5( self::fingerprint( $ua ) . '|v' ), 0, 16 );
	}

	/**
	 * Anonymous, daily-changing visitor fingerprint. The IP address itself is never stored.
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	private static function fingerprint( $ua ) {
		$day  = current_time( 'Y-m-d' );
		$salt = get_option( 'lgpi_salt_day' );
		if ( ! is_array( $salt ) || ( $salt['day'] ?? '' ) !== $day ) {
			$salt = array(
				'day'  => $day,
				'salt' => wp_generate_password( 32, true, true ),
			);
			update_option( 'lgpi_salt_day', $salt, false );
		}
		return md5( $salt['salt'] . self::ip() . $ua );
	}

	/**
	 * Where did the visit come from?
	 *
	 * @param string $referrer Referring page.
	 * @param string $query    Landing page query string.
	 * @return string One of LGPI_DB::SOURCES.
	 */
	public static function source( $referrer, $query ) {
		parse_str( ltrim( $query, '?' ), $q );
		$medium = strtolower( (string) ( $q['utm_medium'] ?? '' ) );
		$src    = strtolower( (string) ( $q['utm_source'] ?? '' ) );
		foreach ( array( 'gclid', 'gbraid', 'wbraid', 'msclkid', 'ttclid', 'ScCid', 'li_fat_id', 'twclid', 'epik' ) as $k ) {
			if ( ! empty( $q[ $k ] ) ) {
				return 'paid';
			}
		}
		if ( preg_match( '/cpc|ppc|paid|cpm|display|banner|retarget/', $medium ) ) {
			return 'paid';
		}
		if ( 'email' === $medium || preg_match( '/newsletter|mailchimp|klaviyo|email/', $src ) ) {
			return 'email';
		}
		$host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
		$own  = strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
		if ( '' === $host || $host === $own || 'www.' . $host === $own || 'www.' . $own === $host ) {
			if ( 'social' === $medium || ! empty( $q['fbclid'] ) ) {
				return 'social';
			}
			return $medium ? 'referral' : 'direct';
		}
		if ( self::ai( $host ) ) {
			return 'ai';
		}
		if ( preg_match( '/(^|\.)(google|bing|yahoo|duckduckgo|yandex|baidu|ecosia|naver|seznam|qwant|search\.brave)\./', $host ) ) {
			return 'search';
		}
		if ( preg_match( '/(facebook|instagram|fb\.me|t\.co$|twitter|x\.com|linkedin|lnkd\.in|tiktok|pinterest|youtube|snapchat|reddit|whatsapp|telegram|threads\.net)/', $host ) ) {
			return 'social';
		}
		return 'referral';
	}

	/**
	 * Referring host without www., or '' for this site / no referrer.
	 *
	 * @param string $referrer Referring page.
	 * @return string
	 */
	private static function referrer_host( $referrer ) {
		$host = strtolower( (string) wp_parse_url( $referrer, PHP_URL_HOST ) );
		$host = (string) preg_replace( '/^(www|m|l|lm)\./', '', $host );
		$own  = (string) preg_replace( '/^www\./', '', strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) ) );
		return ( '' === $host || $host === $own ) ? '' : mb_substr( sanitize_text_field( $host ), 0, 100 );
	}

	/**
	 * Name of the matching entry in a host => pattern list.
	 *
	 * @param string $host Host.
	 * @param array  $map  Name => regex alternatives.
	 * @return string|null
	 */
	private static function match_host( $host, $map ) {
		$h = '.' . trim( $host, '.' ) . '.';
		foreach ( $map as $name => $re ) {
			if ( preg_match( '/\.(?:' . $re . ')\./', $h ) ) {
				return $name;
			}
		}
		return null;
	}

	/**
	 * Search engine of a referring host.
	 *
	 * @param string $host Host.
	 * @return string|null
	 */
	public static function engine( $host ) {
		return self::match_host(
			$host,
			array(
				'Google'     => 'google\.[a-z.]+',
				'Bing'       => 'bing\.com',
				'Yahoo'      => 'yahoo\.[a-z.]+',
				'DuckDuckGo' => 'duckduckgo\.com',
				'Yandex'     => 'yandex\.[a-z.]+',
				'Baidu'      => 'baidu\.com',
				'Ecosia'     => 'ecosia\.org',
				'Naver'      => 'naver\.com',
				'Brave'      => 'search\.brave\.com',
				'Qwant'      => 'qwant\.com',
				'Seznam'     => 'seznam\.cz',
			)
		);
	}

	/**
	 * Social network of a referring host.
	 *
	 * @param string $host Host.
	 * @return string|null
	 */
	public static function social( $host ) {
		return self::match_host(
			$host,
			array(
				'Facebook'    => 'facebook\.com|fb\.me|messenger\.com',
				'Instagram'   => 'instagram\.com',
				'TikTok'      => 'tiktok\.com',
				'YouTube'     => 'youtube\.com|youtu\.be',
				'Pinterest'   => 'pinterest\.[a-z.]+|pin\.it',
				'LinkedIn'    => 'linkedin\.com|lnkd\.in',
				'Reddit'      => 'reddit\.com',
				'Snapchat'    => 'snapchat\.com',
				'WhatsApp'    => 'whatsapp\.com|wa\.me',
				'Threads'     => 'threads\.net|threads\.com',
				'X (Twitter)' => 't\.co|twitter\.com|x\.com',
				'Telegram'    => 'telegram\.org|t\.me',
			)
		);
	}

	/**
	 * AI assistant of a referring host (ChatGPT, Perplexity, Gemini…).
	 *
	 * @param string $host Host.
	 * @return string|null
	 */
	public static function ai( $host ) {
		return self::match_host(
			$host,
			array(
				'ChatGPT'    => 'chatgpt\.com|chat\.openai\.com|openai\.com',
				'Perplexity' => 'perplexity\.ai',
				'Gemini'     => 'gemini\.google\.com|bard\.google\.com',
				'Copilot'    => 'copilot\.microsoft\.com|copilot\.cloud\.microsoft',
				'Claude'     => 'claude\.ai',
				'DeepSeek'   => 'chat\.deepseek\.com|deepseek\.com',
				'Grok'       => 'grok\.com',
				'Meta AI'    => 'meta\.ai',
				'Poe'        => 'poe\.com',
				'You.com'    => 'you\.com',
				'Mistral'    => 'chat\.mistral\.ai',
			)
		);
	}

	/**
	 * Country from the browser's time zone (no IP lookup); Cloudflare's country header when there is no time zone.
	 *
	 * @param string $tz IANA time zone, e.g. Asia/Dubai.
	 * @return string Two-letter country code or ''.
	 */
	public static function country( $tz ) {
		if ( '' !== $tz && in_array( $tz, timezone_identifiers_list(), true ) ) {
			$loc = ( new DateTimeZone( $tz ) )->getLocation();
			if ( ! empty( $loc['country_code'] ) && '??' !== $loc['country_code'] ) {
				return strtoupper( $loc['country_code'] );
			}
		}
		$cf = isset( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ? strtoupper( sanitize_key( wp_unslash( $_SERVER['HTTP_CF_IPCOUNTRY'] ) ) ) : '';
		return preg_match( '/^[A-Z]{2}$/', $cf ) && 'XX' !== $cf ? $cf : '';
	}

	/**
	 * Browser name from the user agent (in-app browsers of social apps first).
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	public static function browser( $ua ) {
		$list = array(
			'Instagram app'    => '/Instagram/',
			'Facebook app'     => '/FBAN|FBAV/',
			'TikTok app'       => '/musical_ly|BytedanceWebview|TikTok/i',
			'Snapchat app'     => '/Snapchat/',
			'Edge'             => '/Edg(e|A|iOS)?\//',
			'Opera'            => '/OPR\/|Opera/',
			'Samsung Internet' => '/SamsungBrowser/',
			'Firefox'          => '/Firefox|FxiOS/',
			'Chrome'           => '/Chrome|CriOS/',
			'Safari'           => '/Safari/',
			'Internet Explorer' => '/MSIE|Trident\//',
		);
		foreach ( $list as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				return $name;
			}
		}
		return 'Other';
	}

	/**
	 * Operating system from the user agent.
	 *
	 * @param string $ua User agent.
	 * @return string
	 */
	public static function os( $ua ) {
		$list = array(
			'iOS'      => '/iPhone|iPad|iPod/',
			'Android'  => '/Android/',
			'Windows'  => '/Windows/',
			'ChromeOS' => '/CrOS/',
			'macOS'    => '/Mac OS X|Macintosh/',
			'Linux'    => '/Linux/',
		);
		foreach ( $list as $name => $re ) {
			if ( preg_match( $re, $ua ) ) {
				return $name;
			}
		}
		return 'Other';
	}

	/**
	 * Browser language, e.g. en, ar, fr.
	 *
	 * @param string $lang navigator.language.
	 * @return string
	 */
	private static function language( $lang ) {
		$lang = strtolower( substr( preg_replace( '/[^A-Za-z\-]/', '', $lang ), 0, 12 ) );
		return (string) strtok( $lang, '-' );
	}

	/**
	 * Lower case (multibyte when available).
	 *
	 * @param string $s Text.
	 * @return string
	 */
	private static function lower( $s ) {
		return function_exists( 'mb_strtolower' ) ? mb_strtolower( $s ) : strtolower( $s );
	}

	/**
	 * Mobile, tablet or desktop.
	 *
	 * @param int    $width Screen width from the browser.
	 * @param string $ua    User agent.
	 * @return string
	 */
	private static function device( $width, $ua ) {
		if ( $width > 0 ) {
			return $width < 768 ? 'mobile' : ( $width < 1100 ? 'tablet' : 'desktop' );
		}
		if ( preg_match( '/ipad|tablet/i', $ua ) ) {
			return 'tablet';
		}
		return preg_match( '/mobi|android|iphone/i', $ua ) ? 'mobile' : 'desktop';
	}

	/**
	 * Add-to-cart per product (staff excluded).
	 *
	 * @param string $key        Cart item key.
	 * @param int    $product_id Product.
	 */
	public static function added_to_cart( $key, $product_id ) {
		$user = wp_get_current_user();
		if ( $user && $user->exists() && array_intersect( (array) LGPI_Plugin::settings()['exclude_roles'], (array) $user->roles ) ) {
			return;
		}
		LGPI_DB::add_to_cart( absint( $product_id ) );
		$ua = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';

		/**
		 * After an add-to-cart is counted. Lets add-ons count it per device.
		 *
		 * @param int    $product_id Product.
		 * @param string $device     mobile, tablet or desktop (from the browser name).
		 */
		do_action( 'lgpi_added_to_cart', absint( $product_id ), self::device( 0, $ua ) );
	}
}
