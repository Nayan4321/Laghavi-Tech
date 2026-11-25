<?php
/**
 * AI crawlers and search engine bots: which ones read the site and which pages they read.
 *
 * Counted on the server when a bot asks for a page (bots don't run the counter script). Only the bot's name and
 * the page are kept — per day, no IP address. Pages served from a full-page cache may not be counted.
 *
 * @package PerformanceInsight
 */

defined( 'ABSPATH' ) || exit;

/**
 * Bot report.
 */
class LGPI_Bots {

	/** User-agent pattern => name of AI crawlers (training, search and live "browse" fetches). */
	const AI = array(
		'GPTBot'              => 'OpenAI GPTBot (ChatGPT training)',
		'OAI-SearchBot'       => 'OpenAI SearchBot (ChatGPT search)',
		'ChatGPT-User'        => 'ChatGPT (live browsing)',
		'ClaudeBot'           => 'Anthropic ClaudeBot',
		'Claude-User'         => 'Claude (live browsing)',
		'Claude-SearchBot'    => 'Claude SearchBot',
		'anthropic-ai'        => 'Anthropic',
		'PerplexityBot'       => 'PerplexityBot',
		'Perplexity-User'     => 'Perplexity (live browsing)',
		'Google-CloudVertexBot' => 'Google Vertex AI',
		'Gemini-Deep-Research' => 'Google Gemini Deep Research',
		'Meta-ExternalAgent'  => 'Meta AI',
		'meta-externalfetcher' => 'Meta AI (live fetch)',
		'Bytespider'          => 'ByteDance (Doubao)',
		'Amazonbot'           => 'Amazon (Alexa / Rufus)',
		'CCBot'               => 'Common Crawl',
		'cohere-ai'           => 'Cohere',
		'DuckAssistBot'       => 'DuckDuckGo DuckAssist',
		'MistralAI-User'      => 'Mistral Le Chat',
		'YouBot'              => 'You.com',
		'Diffbot'             => 'Diffbot',
		'AI2Bot'              => 'Allen Institute AI2',
		'DeepSeekBot'         => 'DeepSeek',
	);

	/** User-agent pattern => name of search engine crawlers. */
	const SEARCH = array(
		'Googlebot'       => 'Google',
		'bingbot'         => 'Bing',
		'YandexBot'       => 'Yandex',
		'Baiduspider'     => 'Baidu',
		'DuckDuckBot'     => 'DuckDuckGo',
		'Applebot'        => 'Apple (Siri / Spotlight)',
		'Slurp'           => 'Yahoo',
		'PetalBot'        => 'Petal (Huawei)',
		'SeznamBot'       => 'Seznam',
		'Naver'           => 'Naver',
	);

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'template_redirect', array( __CLASS__, 'count' ), 99 );
	}

	/**
	 * Which known bot is this user agent? [kind, name] or null.
	 *
	 * @param string $ua User agent.
	 * @return array|null
	 */
	public static function detect( $ua ) {
		if ( '' === $ua ) {
			return null;
		}
		foreach ( self::AI as $k => $name ) {
			if ( false !== stripos( $ua, $k ) ) {
				return array( 'ai', $name );
			}
		}
		foreach ( self::SEARCH as $k => $name ) {
			if ( false !== stripos( $ua, $k ) ) {
				return array( 'search', $name );
			}
		}
		return null;
	}

	/**
	 * Count a page read by a known bot.
	 */
	public static function count() {
		if ( is_admin() || is_feed() || is_robots() || is_preview() || is_404() || wp_doing_ajax() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}
		$ua  = isset( $_SERVER['HTTP_USER_AGENT'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_USER_AGENT'] ) ) : '';
		$bot = self::detect( $ua );
		if ( ! $bot ) {
			return;
		}
		if ( 'ai' === $bot[0] ) {
			list( $type, $id ) = LGPI_Tracker::current_object();
			LGPI_DB::add_dims(
				array(
					array( 'aibot', $bot[1] ),
					array( 'aibotpg', $type . ':' . (int) $id ),
				),
				false
			);
		} else {
			LGPI_DB::add_dims( array( array( 'srchbot', $bot[1] ) ), false );
		}
	}

	/**
	 * Counts for a period (for the weekly email and the Pro add-on).
	 *
	 * @param string $since Y-m-d.
	 * @param string $until Y-m-d.
	 * @return array ['ai' => [name => reads], 'search' => [...], 'pages' => [type:id => reads]]
	 */
	public static function summary( $since, $until ) {
		$out = array(
			'ai'     => array(),
			'search' => array(),
			'pages'  => array(),
		);
		foreach ( array(
			'aibot'   => 'ai',
			'srchbot' => 'search',
			'aibotpg' => 'pages',
		) as $dim => $k ) {
			foreach ( LGPI_DB::dims( $dim, $since, $until, 50 ) as $r ) {
				$out[ $k ][ $r['k'] ] = (int) $r['views'];
			}
		}
		return $out;
	}
}
