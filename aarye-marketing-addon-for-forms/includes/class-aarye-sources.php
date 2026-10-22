<?php
/**
 * Where a visitor came from: ad networks (by click ID or UTM tags), search engines, social networks,
 * AI assistants, email, other websites, or direct.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Traffic sources.
 */
class AARYE_Sources {

	/**
	 * Channels, in the order they are shown.
	 *
	 * @return array<string, string>
	 */
	public static function channels() {
		return array(
			'paid'           => __( 'Paid ads', 'aarye-marketing-addon-for-forms' ),
			'organic_search' => __( 'Search engines', 'aarye-marketing-addon-for-forms' ),
			'organic_social' => __( 'Social media', 'aarye-marketing-addon-for-forms' ),
			'ai'             => __( 'AI assistants', 'aarye-marketing-addon-for-forms' ),
			'email'          => __( 'Email', 'aarye-marketing-addon-for-forms' ),
			'referral'       => __( 'Other websites', 'aarye-marketing-addon-for-forms' ),
			'direct'         => __( 'Direct', 'aarye-marketing-addon-for-forms' ),
		);
	}

	/**
	 * Every ad network the plugin recognises. "click" is the parameter the network adds to ad links by
	 * itself (auto-tagging); "utm" are utm_source values people use for it; "macros" says whether the
	 * guide can give ready-made URL parameters with campaign/ad names filled in by the network.
	 *
	 * @return array<string, array{name: string, click: string[], utm: string[], domains: string[], covers: string}>
	 */
	public static function ad_networks() {
		return array(
			'google_ads'    => array(
				'name'    => 'Google Ads',
				'click'   => array( 'gclid', 'gbraid', 'wbraid', 'gad_source' ),
				'utm'     => array( 'google', 'googleads', 'google_ads', 'adwords', 'youtube', 'yt', 'pmax', 'gdn' ),
				'domains' => array(),
				'covers'  => 'Search, Display, YouTube, Shopping, Performance Max, Demand Gen',
			),
			'google_dv360'  => array(
				'name'    => 'Google Display & Video 360',
				'click'   => array( 'dclid' ),
				'utm'     => array( 'dv360', 'dbm' ),
				'domains' => array(),
				'covers'  => 'Programmatic display and video',
			),
			'meta_ads'      => array(
				'name'    => 'Meta Ads',
				'click'   => array(),
				'utm'     => array( 'facebook', 'fb', 'instagram', 'ig', 'meta', 'messenger', 'an', 'audience_network', 'threads' ),
				'domains' => array(),
				'covers'  => 'Facebook, Instagram, Messenger, Threads, Audience Network',
			),
			'microsoft_ads' => array(
				'name'    => 'Microsoft Advertising',
				'click'   => array( 'msclkid' ),
				'utm'     => array( 'bing', 'microsoft', 'msn', 'microsoftads', 'bingads' ),
				'domains' => array(),
				'covers'  => 'Bing, Yahoo, DuckDuckGo, MSN, Microsoft Audience Network',
			),
			'tiktok_ads'    => array(
				'name'    => 'TikTok Ads',
				'click'   => array( 'ttclid' ),
				'utm'     => array( 'tiktok', 'tt' ),
				'domains' => array(),
				'covers'  => 'TikTok, Pangle',
			),
			'linkedin_ads'  => array(
				'name'    => 'LinkedIn Ads',
				'click'   => array( 'li_fat_id' ),
				'utm'     => array( 'linkedin', 'li' ),
				'domains' => array(),
				'covers'  => 'Sponsored content, Message ads, Text ads',
			),
			'x_ads'         => array(
				'name'    => 'X (Twitter) Ads',
				'click'   => array( 'twclid' ),
				'utm'     => array( 'twitter', 'x', 'x.com' ),
				'domains' => array(),
				'covers'  => 'Promoted posts',
			),
			'snapchat_ads'  => array(
				'name'    => 'Snapchat Ads',
				'click'   => array( 'sccid', 'ScCid' ),
				'utm'     => array( 'snapchat', 'snap' ),
				'domains' => array(),
				'covers'  => 'Snap ads, Story ads',
			),
			'pinterest_ads' => array(
				'name'    => 'Pinterest Ads',
				'click'   => array( 'epik' ),
				'utm'     => array( 'pinterest', 'pin' ),
				'domains' => array(),
				'covers'  => 'Promoted pins',
			),
			'reddit_ads'    => array(
				'name'    => 'Reddit Ads',
				'click'   => array( 'rdt_cid' ),
				'utm'     => array( 'reddit' ),
				'domains' => array(),
				'covers'  => 'Promoted posts',
			),
			'quora_ads'     => array(
				'name'    => 'Quora Ads',
				'click'   => array( 'qclid' ),
				'utm'     => array( 'quora' ),
				'domains' => array(),
				'covers'  => 'Promoted answers, image ads',
			),
			'yandex_direct' => array(
				'name'    => 'Yandex Direct',
				'click'   => array( 'yclid' ),
				'utm'     => array( 'yandex' ),
				'domains' => array(),
				'covers'  => 'Yandex search and display',
			),
			'amazon_ads'    => array(
				'name'    => 'Amazon Ads',
				'click'   => array(),
				'utm'     => array( 'amazon', 'amazonads', 'amazon_dsp' ),
				'domains' => array(),
				'covers'  => 'Amazon DSP',
			),
			'apple_ads'     => array(
				'name'    => 'Apple Search Ads',
				'click'   => array(),
				'utm'     => array( 'apple', 'apple_search_ads', 'asa' ),
				'domains' => array(),
				'covers'  => 'App Store',
			),
			'spotify_ads'   => array(
				'name'    => 'Spotify Ads',
				'click'   => array(),
				'utm'     => array( 'spotify' ),
				'domains' => array(),
				'covers'  => 'Audio and video ads',
			),
			'taboola'       => array(
				'name'    => 'Taboola',
				'click'   => array( 'tblci' ),
				'utm'     => array( 'taboola' ),
				'domains' => array(),
				'covers'  => 'Native ads',
			),
			'outbrain'      => array(
				'name'    => 'Outbrain',
				'click'   => array( 'obclid', 'dicbo' ),
				'utm'     => array( 'outbrain' ),
				'domains' => array(),
				'covers'  => 'Native ads',
			),
			'criteo'        => array(
				'name'    => 'Criteo',
				'click'   => array(),
				'utm'     => array( 'criteo' ),
				'domains' => array(),
				'covers'  => 'Retargeting',
			),
			'adroll'        => array(
				'name'    => 'AdRoll',
				'click'   => array(),
				'utm'     => array( 'adroll' ),
				'domains' => array(),
				'covers'  => 'Retargeting',
			),
			'stackadapt'    => array(
				'name'    => 'StackAdapt',
				'click'   => array(),
				'utm'     => array( 'stackadapt' ),
				'domains' => array(),
				'covers'  => 'Programmatic',
			),
			'trade_desk'    => array(
				'name'    => 'The Trade Desk',
				'click'   => array(),
				'utm'     => array( 'thetradedesk', 'tradedesk', 'ttd' ),
				'domains' => array(),
				'covers'  => 'Programmatic',
			),
			'baidu_ads'     => array(
				'name'    => 'Baidu Ads',
				'click'   => array(),
				'utm'     => array( 'baidu' ),
				'domains' => array(),
				'covers'  => 'Baidu search',
			),
			'naver_ads'     => array(
				'name'    => 'Naver Ads',
				'click'   => array(),
				'utm'     => array( 'naver' ),
				'domains' => array(),
				'covers'  => 'Naver search',
			),
			'whatsapp_ads'  => array(
				'name'    => 'Click-to-WhatsApp ads',
				'click'   => array(),
				'utm'     => array( 'whatsapp', 'wa' ),
				'domains' => array(),
				'covers'  => 'Meta ads that open WhatsApp',
			),
		);
	}

	/**
	 * Search engines by referrer host (a part of the host is enough).
	 *
	 * @return array<string, string>
	 */
	public static function search_engines() {
		return array(
			'google.'          => 'Google',
			'bing.com'         => 'Bing',
			'search.yahoo.'    => 'Yahoo',
			'duckduckgo.com'   => 'DuckDuckGo',
			'baidu.com'        => 'Baidu',
			'yandex.'          => 'Yandex',
			'ecosia.org'       => 'Ecosia',
			'naver.com'        => 'Naver',
			'search.brave.com' => 'Brave Search',
			'startpage.com'    => 'Startpage',
			'seznam.cz'        => 'Seznam',
			'ask.com'          => 'Ask',
			'qwant.com'        => 'Qwant',
			'yahoo.co.jp'      => 'Yahoo Japan',
		);
	}

	/**
	 * Social networks by referrer host.
	 *
	 * @return array<string, string>
	 */
	public static function social_networks() {
		return array(
			'facebook.com'  => 'Facebook',
			'fb.me'         => 'Facebook',
			'instagram.com' => 'Instagram',
			'threads.net'   => 'Threads',
			't.co'          => 'X (Twitter)',
			'twitter.com'   => 'X (Twitter)',
			'x.com'         => 'X (Twitter)',
			'linkedin.com'  => 'LinkedIn',
			'lnkd.in'       => 'LinkedIn',
			'tiktok.com'    => 'TikTok',
			'youtube.com'   => 'YouTube',
			'youtu.be'      => 'YouTube',
			'pinterest.'    => 'Pinterest',
			'reddit.com'    => 'Reddit',
			'quora.com'     => 'Quora',
			'snapchat.com'  => 'Snapchat',
			'whatsapp.com'  => 'WhatsApp',
			'wa.me'         => 'WhatsApp',
			't.me'          => 'Telegram',
			'telegram.org'  => 'Telegram',
			'vk.com'        => 'VK',
			'medium.com'    => 'Medium',
			'tumblr.com'    => 'Tumblr',
			'discord.com'   => 'Discord',
		);
	}

	/**
	 * AI assistants by referrer host or utm_source.
	 *
	 * @return array<string, string>
	 */
	public static function ai_assistants() {
		return array(
			'chatgpt.com'         => 'ChatGPT',
			'chat.openai.com'     => 'ChatGPT',
			'perplexity.ai'       => 'Perplexity',
			'gemini.google.com'   => 'Gemini',
			'bard.google.com'     => 'Gemini',
			'copilot.microsoft.com' => 'Microsoft Copilot',
			'claude.ai'           => 'Claude',
			'you.com'             => 'You.com',
			'poe.com'             => 'Poe',
			'chat.deepseek.com'   => 'DeepSeek',
			'meta.ai'             => 'Meta AI',
			'grok.com'            => 'Grok',
		);
	}

	/**
	 * Webmail hosts.
	 *
	 * @return string[]
	 */
	public static function webmail() {
		return array( 'mail.google.com', 'outlook.live.com', 'outlook.office.com', 'mail.yahoo.', 'mail.proton.me', 'mail.zoho.', 'webmail.' );
	}

	/**
	 * utm_medium values that mean paid traffic.
	 *
	 * @return string[]
	 */
	public static function paid_mediums() {
		return array( 'cpc', 'ppc', 'paid', 'paidsearch', 'paid_search', 'paid-search', 'paidsocial', 'paid_social', 'paid-social', 'cpm', 'cpa', 'cpv', 'display', 'banner', 'ads', 'ad', 'sponsored', 'retargeting', 'remarketing', 'native', 'video_ads', 'shopping', 'pmax', 'paid_video' );
	}

	/**
	 * The click-ID parameters to keep from landing URLs.
	 *
	 * @return string[]
	 */
	public static function click_params() {
		$params = array();
		foreach ( self::ad_networks() as $network ) {
			$params = array_merge( $params, $network['click'] );
		}
		return array_values( array_unique( array_merge( $params, array( 'fbclid', 'igshid', 'utm_id', 'utm_adgroup', 'campaign_id', 'campaign_name', 'adset_id', 'adset_name', 'adgroup_id', 'adgroup_name', 'ad_id', 'ad_name', 'creative', 'keyword', 'matchtype', 'network', 'device', 'placement', 'site_source_name' ) ) ) );
	}

	/**
	 * Host of a URL, without "www.".
	 *
	 * @param string $url URL.
	 * @return string
	 */
	private static function host( $url ) {
		$host = strtolower( (string) wp_parse_url( $url, PHP_URL_HOST ) );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Whether a host matches a pattern from the lists above ("google." matches google.com and google.ae).
	 *
	 * @param string $host    Host.
	 * @param string $pattern Pattern.
	 * @return bool
	 */
	private static function host_is( $host, $pattern ) {
		if ( '.' === substr( $pattern, -1 ) ) {
			// "google." matches google.com, google.co.uk and news.google.com.
			return 0 === strpos( $host, $pattern ) || false !== strpos( $host, '.' . $pattern );
		}
		return $host === $pattern || substr( $host, -strlen( '.' . $pattern ) ) === '.' . $pattern;
	}

	/**
	 * First non-empty parameter of a list.
	 *
	 * @param array    $p    Parameters.
	 * @param string[] $keys Keys, most specific first.
	 * @return string
	 */
	private static function first( $p, $keys ) {
		foreach ( $keys as $key ) {
			if ( isset( $p[ $key ] ) && '' !== trim( (string) $p[ $key ] ) ) {
				return (string) $p[ $key ];
			}
		}
		return '';
	}

	/**
	 * Name for a host from one of the lists.
	 *
	 * @param string                $host Host.
	 * @param array<string, string> $list Pattern => name.
	 * @return string Empty if none matches.
	 */
	private static function match_host( $host, $list ) {
		foreach ( $list as $pattern => $name ) {
			if ( self::host_is( $host, $pattern ) ) {
				return $name;
			}
		}
		return '';
	}

	/**
	 * Classify one visit ("touch").
	 *
	 * @param array $touch Keys: params (array of URL parameters), ref (referrer URL), url (landing URL).
	 * @return array{channel: string, source: string, label: string, campaign: string, ad_group: string, ad: string, keyword: string, click_id: string, click_param: string, confirmed: bool}
	 */
	public static function classify( $touch ) {
		$p      = isset( $touch['params'] ) && is_array( $touch['params'] ) ? array_change_key_case( $touch['params'], CASE_LOWER ) : array();
		$ref    = isset( $touch['ref'] ) ? (string) $touch['ref'] : '';
		$host   = '' !== $ref ? self::host( $ref ) : '';
		$own    = self::host( home_url() );
		$source = isset( $p['utm_source'] ) ? strtolower( trim( $p['utm_source'] ) ) : '';
		$medium = isset( $p['utm_medium'] ) ? strtolower( trim( $p['utm_medium'] ) ) : '';
		$out    = array(
			'channel'     => 'direct',
			'source'      => 'direct',
			'label'       => __( 'Direct', 'aarye-marketing-addon-for-forms' ),
			'campaign'    => self::first( $p, array( 'utm_campaign', 'campaign_name', 'campaign_id', 'utm_id' ) ),
			'ad_group'    => self::first( $p, array( 'utm_adgroup', 'adset_name', 'adgroup_name', 'adset_id', 'adgroup_id' ) ),
			'ad'          => self::first( $p, array( 'utm_content', 'ad_name', 'ad_id', 'creative' ) ),
			'keyword'     => self::first( $p, array( 'utm_term', 'keyword' ) ),
			'click_id'    => '',
			'click_param' => '',
			'confirmed'   => true,
		);

		// 1. Auto-tagging click IDs: the ad network added them, so this is certainly a paid click.
		foreach ( self::ad_networks() as $key => $network ) {
			foreach ( $network['click'] as $param ) {
				$param = strtolower( $param );
				if ( isset( $p[ $param ] ) && '' !== $p[ $param ] ) {
					if ( 'gad_source' === $param && ( isset( $p['gclid'] ) || isset( $p['gbraid'] ) || isset( $p['wbraid'] ) ) ) {
						continue;
					}
					return array_merge(
						$out,
						array(
							'channel'     => 'paid',
							'source'      => $key,
							'label'       => $network['name'],
							'click_id'    => $p[ $param ],
							'click_param' => $param,
						)
					);
				}
			}
		}

		// 2. UTM tags.
		if ( '' !== $source ) {
			$is_paid = in_array( $medium, self::paid_mediums(), true ) || false !== strpos( $medium, 'paid' ) || false !== strpos( $medium, 'cpc' );
			foreach ( self::ad_networks() as $key => $network ) {
				if ( in_array( $source, $network['utm'], true ) && $is_paid ) {
					$fb = isset( $p['fbclid'] ) ? $p['fbclid'] : '';
					return array_merge(
						$out,
						array(
							'channel'     => 'paid',
							'source'      => $key,
							'label'       => $network['name'],
							'click_id'    => $fb,
							'click_param' => '' !== $fb ? 'fbclid' : '',
						)
					);
				}
			}
			if ( $is_paid ) {
				return array_merge(
					$out,
					array(
						'channel' => 'paid',
						'source'  => 'other_ads',
						/* translators: %s: utm_source value. */
						'label'   => sprintf( __( 'Paid: %s', 'aarye-marketing-addon-for-forms' ), $p['utm_source'] ),
					)
				);
			}
			if ( in_array( $medium, array( 'email', 'e-mail', 'newsletter', 'mail' ), true ) ) {
				return array_merge( $out, array( 'channel' => 'email', 'source' => 'email', 'label' => sprintf( /* translators: %s: utm_source value. */ __( 'Email: %s', 'aarye-marketing-addon-for-forms' ), $p['utm_source'] ) ) );
			}
			$ai = self::match_host( $source, self::ai_assistants() );
			if ( '' !== $ai ) {
				return array_merge( $out, array( 'channel' => 'ai', 'source' => 'ai', 'label' => $ai ) );
			}
			if ( in_array( $medium, array( 'social', 'organic_social', 'social-organic', 'post', 'bio', 'story' ), true ) ) {
				return array_merge( $out, array( 'channel' => 'organic_social', 'source' => 'social', 'label' => ucfirst( $p['utm_source'] ) ) );
			}
			if ( in_array( $medium, array( 'organic', 'seo' ), true ) ) {
				return array_merge( $out, array( 'channel' => 'organic_search', 'source' => 'search', 'label' => ucfirst( $p['utm_source'] ) ) );
			}
			return array_merge( $out, array( 'channel' => 'referral', 'source' => 'tagged', 'label' => $p['utm_source'] . ( '' !== $medium ? ' / ' . $p['utm_medium'] : '' ) ) );
		}

		// 3. Facebook/Instagram adds fbclid to every outgoing link, ads and posts alike.
		if ( isset( $p['fbclid'] ) && '' !== $p['fbclid'] ) {
			return array_merge(
				$out,
				array(
					'channel'     => 'organic_social',
					'source'      => 'meta',
					'label'       => __( 'Facebook / Instagram', 'aarye-marketing-addon-for-forms' ),
					'click_id'    => $p['fbclid'],
					'click_param' => 'fbclid',
					'confirmed'   => false,
				)
			);
		}

		// 4. Referrer.
		if ( '' === $host || $host === $own ) {
			return $out;
		}
		$name = self::match_host( $host, self::ai_assistants() );
		if ( '' !== $name ) {
			return array_merge( $out, array( 'channel' => 'ai', 'source' => 'ai', 'label' => $name ) );
		}
		foreach ( self::webmail() as $pattern ) {
			if ( self::host_is( $host, $pattern ) ) {
				return array_merge( $out, array( 'channel' => 'email', 'source' => 'email', 'label' => __( 'Email', 'aarye-marketing-addon-for-forms' ) ) );
			}
		}
		$name = self::match_host( $host, self::search_engines() );
		if ( '' !== $name ) {
			return array_merge( $out, array( 'channel' => 'organic_search', 'source' => 'search', 'label' => $name ) );
		}
		$name = self::match_host( $host, self::social_networks() );
		if ( '' !== $name ) {
			return array_merge( $out, array( 'channel' => 'organic_social', 'source' => 'social', 'label' => $name ) );
		}
		return array_merge( $out, array( 'channel' => 'referral', 'source' => 'referral', 'label' => $host ) );
	}

	/**
	 * Name of a channel.
	 *
	 * @param string $channel Channel key.
	 * @return string
	 */
	public static function channel_label( $channel ) {
		$all = self::channels();
		return isset( $all[ $channel ] ) ? $all[ $channel ] : $channel;
	}
}
