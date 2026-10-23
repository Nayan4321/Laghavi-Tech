<?php
/**
 * What is sent where: the full entry (webhooks, databases), the privacy-safe lead for Laghavi Ads Hub,
 * and {tokens} for field mapping in CRMs.
 *
 * @package AaryeMarketingAddonPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Payload builder.
 */
class AARYEPRO_Payload {

	/**
	 * Click IDs Laghavi Ads Hub accepts.
	 */
	const HUB_CLICK_IDS = array( 'gclid', 'gbraid', 'wbraid', 'dclid', 'fbclid', 'msclkid', 'ttclid', 'li_fat_id', 'twclid', 'sccid', 'epik', 'rdt_cid', 'qclid', 'yclid', 'tblci' );

	/**
	 * Ad networks Laghavi Ads Hub knows; others are sent as other_ads.
	 */
	const HUB_NETWORKS = array( 'google_ads', 'meta_ads', 'tiktok_ads', 'microsoft_ads', 'linkedin_ads', 'snapchat_ads', 'pinterest_ads', 'reddit_ads', 'x_ads', 'amazon_ads', 'quora_ads', 'yandex_ads', 'taboola', 'outbrain', 'criteo', 'apple_search_ads', 'spotify_ads', 'youtube_ads', 'other_ads' );

	/**
	 * Stable ID of an entry across systems: {form plugin}-{form id}-{entry id}.
	 *
	 * @param object $e Entry.
	 * @return string
	 */
	public static function lead_id( $e ) {
		return substr( sanitize_key( $e->form_plugin ) . '-' . preg_replace( '/[^A-Za-z0-9_-]/', '', (string) $e->form_id ) . '-' . (int) $e->id, 0, 120 );
	}

	/**
	 * First and last name from a full name.
	 *
	 * @param string $name Full name.
	 * @return array{0: string, 1: string}
	 */
	public static function split_name( $name ) {
		$name  = trim( preg_replace( '/\s+/u', ' ', (string) $name ) );
		$parts = '' === $name ? array() : explode( ' ', $name );
		if ( count( $parts ) < 2 ) {
			return array( $name, '' );
		}
		$last = array_pop( $parts );
		return array( implode( ' ', $parts ), $last );
	}

	/**
	 * Date in ISO 8601 with the site's time zone.
	 *
	 * @param string $gmt MySQL date in UTC.
	 * @return string
	 */
	public static function iso( $gmt ) {
		return $gmt ? get_date_from_gmt( $gmt, 'c' ) : '';
	}

	/**
	 * A touch (first or last visit) with its source.
	 *
	 * @param array|null $touch Touch from the entry's attribution.
	 * @return array|null
	 */
	private static function touch( $touch ) {
		if ( ! is_array( $touch ) ) {
			return null;
		}
		$source = AARYE_Sources::classify( $touch );
		return array_merge(
			$source,
			array(
				'time'     => ! empty( $touch['time'] ) ? gmdate( 'c', (int) $touch['time'] ) : '',
				'url'      => isset( $touch['url'] ) ? (string) $touch['url'] : '',
				'referrer' => isset( $touch['ref'] ) ? (string) $touch['ref'] : '',
				'params'   => isset( $touch['params'] ) && is_array( $touch['params'] ) ? $touch['params'] : array(),
			)
		);
	}

	/**
	 * Address of the entry in WordPress.
	 *
	 * @param object $e Entry.
	 * @return string
	 */
	public static function admin_link( $e ) {
		return admin_url( 'admin.php?page=aarye-leads-entries&entry=' . (int) $e->id );
	}

	/**
	 * Files with an address that works outside WordPress where possible.
	 *
	 * @param object $e Entry.
	 * @return array<int, array{label: string, name: string, url: string}>
	 */
	public static function files( $e ) {
		$out = array();
		foreach ( AARYE_Entries::json( $e, 'files' ) as $file ) {
			$out[] = array(
				'label' => (string) $file['label'],
				'name'  => (string) $file['name'],
				'url'   => '' !== (string) $file['url'] ? (string) $file['url'] : self::admin_link( $e ),
			);
		}
		return $out;
	}

	/**
	 * The full entry, for webhooks, custom CRMs and databases.
	 *
	 * @param object $e     Entry.
	 * @param string $event entry.created or entry.updated.
	 * @return array
	 */
	public static function entry( $e, $event = 'entry.created' ) {
		$attr          = AARYE_Entries::json( $e, 'attribution' );
		list( $f, $l ) = self::split_name( $e->name );
		$data          = array(
			'event'          => $event,
			'site'           => home_url(),
			'plugin_version' => AARYEPRO_VERSION,
			'entry'          => array(
				'id'           => (int) $e->id,
				'lead_id'      => self::lead_id( $e ),
				'created_at'   => self::iso( $e->created_at ),
				'status'       => $e->status,
				'form'         => array(
					'plugin'   => $e->form_plugin,
					'id'       => (string) $e->form_id,
					'name'     => $e->form_name,
					'entry_id' => (string) $e->source_entry_id,
				),
				'contact'      => array(
					'name'       => $e->name,
					'first_name' => $f,
					'last_name'  => $l,
					'email'      => $e->email,
					'phone'      => $e->phone,
				),
				'fields'       => AARYE_Entries::json( $e, 'fields' ),
				'files'        => self::files( $e ),
				'source'       => array(
					'channel'     => $e->channel,
					'network'     => 'paid' === $e->channel ? $e->source : '',
					'label'       => $e->source_label,
					'campaign'    => $e->campaign,
					'ad_group'    => $e->ad_group,
					'ad'          => $e->ad,
					'keyword'     => $e->keyword,
					'click_param' => $e->click_param,
					'click_id'    => $e->click_id,
					'confirmed'   => (bool) $e->confirmed,
				),
				'first_touch'  => self::touch( isset( $attr['first'] ) ? $attr['first'] : null ),
				'last_touch'   => self::touch( isset( $attr['last'] ) ? $attr['last'] : null ),
				'landing_page' => (string) $e->landing_page,
				'referrer'     => (string) $e->referrer,
				'page_url'     => (string) $e->page_url,
				'language'     => $e->language,
				'device'       => $e->device,
				'browser'      => $e->browser,
				'visits'       => (int) $e->visits,
				'visitor_id'   => $e->visitor_id,
				'ip'           => $e->ip,
				'admin_url'    => self::admin_link( $e ),
			),
		);
		/**
		 * Change the entry sent to webhooks and databases.
		 *
		 * @param array  $data  Payload.
		 * @param object $e     Entry.
		 */
		return (array) apply_filters( 'aaryepro_entry_payload', $data, $e );
	}

	/**
	 * Hub network key for an Aarye ad network.
	 *
	 * @param string $source Aarye source key.
	 * @return string
	 */
	public static function hub_network( $source ) {
		$map = array(
			'yandex_direct' => 'yandex_ads',
			'apple_ads'     => 'apple_search_ads',
			'whatsapp_ads'  => 'meta_ads', // Bought in Meta Ads Manager.
		);
		$key = isset( $map[ $source ] ) ? $map[ $source ] : $source;
		return in_array( $key, self::HUB_NETWORKS, true ) ? $key : 'other_ads';
	}

	/**
	 * One touch in the hub's shape.
	 *
	 * @param array|null $touch Touch.
	 * @return array|null
	 */
	private static function hub_touch( $touch ) {
		if ( ! is_array( $touch ) ) {
			return null;
		}
		$p      = isset( $touch['params'] ) && is_array( $touch['params'] ) ? $touch['params'] : array();
		$source = AARYE_Sources::classify( $touch );
		$get    = static function ( $keys ) use ( $p ) {
			foreach ( (array) $keys as $k ) {
				if ( isset( $p[ $k ] ) && '' !== (string) $p[ $k ] ) {
					return mb_substr( (string) $p[ $k ], 0, 200 );
				}
			}
			return '';
		};
		$clicks = array();
		foreach ( self::HUB_CLICK_IDS as $k ) {
			if ( '' !== $get( $k ) ) {
				$clicks[ $k ] = $get( $k );
			}
		}
		$ids = array_filter(
			array(
				'campaign_id' => mb_substr( $get( array( 'campaign_id', 'utm_id' ) ), 0, 40 ),
				'adset_id'    => mb_substr( $get( array( 'adset_id', 'adgroup_id' ) ), 0, 40 ),
				'ad_id'       => mb_substr( $get( array( 'ad_id', 'creative_id' ) ), 0, 40 ),
			),
			'strlen'
		);
		return array(
			'utm'         => array_filter(
				array(
					'source'   => $get( 'utm_source' ),
					'medium'   => $get( 'utm_medium' ),
					'campaign' => '' !== $get( 'utm_campaign' ) ? $get( 'utm_campaign' ) : mb_substr( $source['campaign'], 0, 200 ),
					'content'  => '' !== $get( 'utm_content' ) ? $get( 'utm_content' ) : mb_substr( $source['ad'], 0, 200 ),
					'term'     => '' !== $get( 'utm_term' ) ? $get( 'utm_term' ) : mb_substr( $source['keyword'], 0, 200 ),
				),
				'strlen'
			),
			'click_ids'   => $clicks,
			'ids'         => $ids,
			'landing_url' => isset( $touch['url'] ) ? (string) $touch['url'] : '',
			'referrer'    => isset( $touch['ref'] ) ? (string) $touch['ref'] : '',
			'channel'     => $source['channel'],
			'network'     => 'paid' === $source['channel'] ? self::hub_network( $source['source'] ) : '',
		);
	}

	/**
	 * The lead for Laghavi Ads Hub. Never contains names, emails, phone numbers, IP addresses or messages.
	 *
	 * @param object $e Entry.
	 * @return array
	 */
	public static function hub_lead( $e ) {
		$attr  = AARYE_Entries::json( $e, 'attribution' );
		$first = isset( $attr['first'] ) && is_array( $attr['first'] ) ? $attr['first'] : null;
		$last  = isset( $attr['last'] ) && is_array( $attr['last'] ) ? $attr['last'] : null;
		$main  = self::hub_touch( $first ? $first : $last );
		$lead  = array(
			'id'         => self::lead_id( $e ),
			'created_at' => self::iso( $e->created_at ),
			'status'     => in_array( $e->status, array( 'new', 'contacted', 'qualified', 'won', 'lost', 'spam' ), true ) ? $e->status : 'new',
			'form'       => mb_substr( $e->form_name, 0, 190 ),
			'page_url'   => (string) $e->page_url,
			'device'     => in_array( $e->device, array( 'mobile', 'desktop', 'tablet' ), true ) ? $e->device : 'desktop',
		);
		if ( $main ) {
			$lead = array_merge( $lead, $main );
		} else {
			$lead['channel'] = $e->channel;
			$lead['network'] = 'paid' === $e->channel ? self::hub_network( $e->source ) : '';
		}
		if ( $first && $last && $first !== $last ) {
			$lead['last_touch'] = self::hub_touch( $last );
		}
		$value = (string) AARYEPRO_Connections::get( 'hub', 'lead_value' );
		if ( '' !== $value && is_numeric( $value ) && (float) $value >= 0 ) {
			$lead['value']    = (float) $value;
			$lead['currency'] = strtoupper( substr( (string) AARYEPRO_Connections::get( 'hub', 'currency' ), 0, 3 ) );
		}
		foreach ( array( 'utm', 'click_ids', 'ids' ) as $k ) {
			if ( isset( $lead[ $k ] ) && ! $lead[ $k ] ) {
				unset( $lead[ $k ] );
			}
		}
		/**
		 * Change the lead sent to Laghavi Ads Hub (for example to add a value from a form field).
		 * Never add names, emails, phone numbers, IP addresses or message text.
		 *
		 * @param array  $lead Lead.
		 * @param object $e    Entry.
		 */
		return (array) apply_filters( 'aaryepro_hub_lead', $lead, $e );
	}

	/**
	 * Replace {tokens} in a mapping value.
	 *
	 * Tokens: {name} {first_name} {last_name} {email} {phone} {form} {source} {channel} {network} {campaign}
	 * {ad_group} {ad} {keyword} {click_id} {landing_page} {referrer} {page_url} {language} {device}
	 * {status} {date} {entry_url} {all_fields} {field:Label}.
	 *
	 * @param string $text Text with tokens.
	 * @param object $e    Entry.
	 * @return string
	 */
	public static function tokens( $text, $e ) {
		list( $f, $l ) = self::split_name( $e->name );
		$fields        = AARYE_Entries::json( $e, 'fields' );
		$all           = array();
		foreach ( $fields as $field ) {
			$all[] = ( '' !== $field['label'] ? $field['label'] : $field['name'] ) . ': ' . $field['value'];
		}
		$map  = array(
			'{name}'         => $e->name,
			'{first_name}'   => $f,
			'{last_name}'    => $l,
			'{email}'        => $e->email,
			'{phone}'        => $e->phone,
			'{form}'         => $e->form_name,
			'{source}'       => $e->source_label,
			'{channel}'      => AARYE_Sources::channel_label( $e->channel ),
			'{network}'      => 'paid' === $e->channel ? $e->source_label : '',
			'{campaign}'     => $e->campaign,
			'{ad_group}'     => $e->ad_group,
			'{ad}'           => $e->ad,
			'{keyword}'      => $e->keyword,
			'{click_id}'     => $e->click_id,
			'{landing_page}' => (string) $e->landing_page,
			'{referrer}'     => (string) $e->referrer,
			'{page_url}'     => (string) $e->page_url,
			'{language}'     => $e->language,
			'{device}'       => $e->device,
			'{status}'       => $e->status,
			'{date}'         => self::iso( $e->created_at ),
			'{entry_url}'    => self::admin_link( $e ),
			'{all_fields}'   => implode( "\n", $all ),
		);
		$text = strtr( (string) $text, $map );
		return (string) preg_replace_callback(
			'/\{field:([^}]+)\}/u',
			static function ( $m ) use ( $fields ) {
				$want = mb_strtolower( trim( $m[1] ) );
				foreach ( $fields as $field ) {
					if ( mb_strtolower( (string) $field['label'] ) === $want || mb_strtolower( (string) $field['name'] ) === $want ) {
						return (string) $field['value'];
					}
				}
				return '';
			},
			$text
		);
	}

	/**
	 * Parse "target = value with {tokens}" lines.
	 *
	 * @param string $lines Mapping text.
	 * @param object $e     Entry.
	 * @return array<string, string> Target => value (empty values skipped).
	 */
	public static function mapping( $lines, $e ) {
		$out = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) $lines ) as $line ) {
			if ( false === strpos( $line, '=' ) ) {
				continue;
			}
			list( $target, $value ) = array_map( 'trim', explode( '=', $line, 2 ) );
			$value                  = trim( self::tokens( $value, $e ) );
			if ( '' !== $target && '' !== $value ) {
				$out[ $target ] = $value;
			}
		}
		return $out;
	}
}
