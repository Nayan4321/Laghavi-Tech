<?php
/**
 * Visit evidence: how sure we can be about where a lead came from, and whether a person or a bot sent it.
 *
 * Signals are recorded when a form is sent (see collect()) and explained in plain words on each entry
 * (see analyse()). Nothing here blocks or deletes anything: it explains. Aarye Pro can act on it.
 *
 * @package AaryeMarketingAddon
 */

defined( 'ABSPATH' ) || exit;

/**
 * Evidence.
 */
class AARYE_Evidence {

	/**
	 * Points a "bad" and a "doubtful" sign add to the spam score.
	 */
	const BAD  = 40;
	const WARN = 15;

	/**
	 * Signals of the current request, recorded with a new entry.
	 *
	 * @param array $visit What the tracker remembered (AARYE_Tracker::read()).
	 * @param array $row   The entry about to be saved (for the repeat check).
	 * @return array
	 */
	public static function collect( $visit, $row ) {
		global $wpdb;
		$extra    = isset( $visit['extra'] ) && is_array( $visit['extra'] ) ? $visit['extra'] : array();
		$post_ref = isset( $_SERVER['HTTP_REFERER'] ) ? esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ) : '';
		$lang     = isset( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ? sanitize_text_field( wp_unslash( $_SERVER['HTTP_ACCEPT_LANGUAGE'] ) ) : '';
		$now      = time();
		$out      = array(
			'v'           => 1,
			'tracker'     => isset( $_COOKIE['aarye_attr'] ),
			'consent'     => AARYE_Settings::on( 'wait_for_consent' ),
			'post_ref'    => mb_substr( $post_ref, 0, 300 ),
			'accept_lang' => mb_substr( $lang, 0, 60 ),
			'pages'       => isset( $extra['pv'] ) ? absint( $extra['pv'] ) : null,
			'seconds'     => ! empty( $extra['pt'] ) && absint( $extra['pt'] ) <= $now ? $now - absint( $extra['pt'] ) : null,
			'interacted'  => isset( $extra['ia'] ) ? (bool) $extra['ia'] : null,
			'automation'  => isset( $extra['wd'] ) ? (bool) $extra['wd'] : null,
			'timezone'    => isset( $extra['tz'] ) ? mb_substr( sanitize_text_field( (string) $extra['tz'] ), 0, 60 ) : '',
			'screen'      => isset( $extra['sc'] ) ? mb_substr( preg_replace( '/[^0-9x]/', '', (string) $extra['sc'] ), 0, 12 ) : '',
			'repeat'      => 0,
		);
		// Entries from the same email or browser in the last hour.
		$since = gmdate( 'Y-m-d H:i:s', $now - HOUR_IN_SECONDS );
		$email = isset( $row['email'] ) ? (string) $row['email'] : '';
		$vid   = isset( $row['visitor_id'] ) ? (string) $row['visitor_id'] : '';
		if ( '' !== $email || '' !== $vid ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Our own table.
			$out['repeat'] = (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COUNT(*) FROM %i WHERE created_at >= %s AND ( ( email <> %s AND email = %s ) OR ( visitor_id <> %s AND visitor_id = %s ) )', AARYE_Entries::table(), $since, '', $email, '', $vid ) );
		}
		return $out;
	}

	/**
	 * Host of an address without "www.".
	 *
	 * @param string $url Address.
	 * @return string
	 */
	private static function host( $url ) {
		$host = strtolower( (string) wp_parse_url( (string) $url, PHP_URL_HOST ) );
		return 0 === strpos( $host, 'www.' ) ? substr( $host, 4 ) : $host;
	}

	/**
	 * Main name of a host or source ("l.facebook.com" and "facebook" give "facebook").
	 *
	 * @param string $value Host or utm_source.
	 * @return string
	 */
	private static function brand( $value ) {
		$value   = strtolower( trim( (string) $value ) );
		$aliases = array(
			'fb'   => 'facebook',
			'ig'   => 'instagram',
			'yt'   => 'youtube',
			'li'   => 'linkedin',
			'lnkd' => 'linkedin',
			't'    => 'twitter',
			'x'    => 'twitter',
			'tw'   => 'twitter',
		);
		if ( isset( $aliases[ $value ] ) ) {
			return $aliases[ $value ];
		}
		$value = preg_replace( '#^https?://#', '', $value );
		$parts = array_values( array_filter( explode( '.', preg_replace( '#/.*$#', '', $value ) ) ) );
		if ( count( $parts ) >= 2 ) {
			// themanifest.com → themanifest, google.co.uk → google, l.facebook.com → facebook.
			$tld   = array_pop( $parts );
			$name  = array_pop( $parts );
			$short = array( 'co', 'com', 'org', 'net', 'gov', 'ac', 'edu' );
			if ( in_array( $name, $short, true ) && 2 === strlen( $tld ) && $parts ) {
				$name = array_pop( $parts );
			}
			$value = $name;
		}
		if ( 't' === $value ) {
			return 'twitter';
		}
		return isset( $aliases[ $value ] ) ? $aliases[ $value ] : $value;
	}

	/**
	 * How the source of an entry was decided, and how much it can be trusted.
	 *
	 * @param object $e    Entry.
	 * @param array  $attr Attribution data.
	 * @return array{level: string, label: string, status: string, text: string, steps: array}
	 */
	public static function proof( $e, $attr ) {
		$touch  = ! empty( $attr['last'] ) ? $attr['last'] : ( ! empty( $attr['first'] ) ? $attr['first'] : array() );
		$params = isset( $touch['params'] ) && is_array( $touch['params'] ) ? array_change_key_case( $touch['params'], CASE_LOWER ) : array();
		$ref    = isset( $touch['ref'] ) ? (string) $touch['ref'] : '';
		$host   = self::host( $ref );
		$source = isset( $params['utm_source'] ) ? trim( (string) $params['utm_source'] ) : '';
		$medium = isset( $params['utm_medium'] ) ? strtolower( trim( (string) $params['utm_medium'] ) ) : '';
		$tags   = array();
		foreach ( $params as $k => $v ) {
			if ( 0 === strpos( $k, 'utm_' ) ) {
				$tags[] = $k . '=' . $v;
			}
		}
		$steps = array(
			array( __( 'Tags in the address', 'aarye-marketing-addon-for-forms' ), $tags ? implode( ' · ', $tags ) : __( 'none', 'aarye-marketing-addon-for-forms' ) ),
			array( __( 'Website the browser came from (referrer)', 'aarye-marketing-addon-for-forms' ), '' !== $host ? $host : __( 'none reported', 'aarye-marketing-addon-for-forms' ) ),
			array( __( 'Ad click ID', 'aarye-marketing-addon-for-forms' ), '' !== (string) $e->click_id ? $e->click_param . ' = ' . $e->click_id : __( 'none', 'aarye-marketing-addon-for-forms' ) ),
		);
		$out   = array(
			'level'  => 'none',
			'label'  => __( 'No source info', 'aarye-marketing-addon-for-forms' ),
			'status' => 'info',
			'text'   => __( 'No tags, no click ID and no referrer: the address was typed, opened from a bookmark or an app, or the link hid where it came from. Recorded as Direct.', 'aarye-marketing-addon-for-forms' ),
			'steps'  => $steps,
		);
		if ( ! empty( $attr['imported'] ) ) {
			return array_merge(
				$out,
				array(
					'level' => 'imported',
					'label' => __( 'Not tracked', 'aarye-marketing-addon-for-forms' ),
					'text'  => __( 'This entry was sent before Aarye was installed, so how the visitor arrived was not recorded.', 'aarye-marketing-addon-for-forms' ),
				)
			);
		}
		if ( 'paid' === $e->channel && '' !== (string) $e->click_id && 'fbclid' !== $e->click_param ) {
			return array_merge(
				$out,
				array(
					'level'  => 'click',
					'label'  => __( 'Ad click ID', 'aarye-marketing-addon-for-forms' ),
					'status' => 'ok',
					/* translators: 1: click parameter such as gclid, 2: ad network. */
					'text'   => sprintf( __( 'The address carried %1$s, an ID the ad network adds to each real ad click. You can look this click up in %2$s to confirm it.', 'aarye-marketing-addon-for-forms' ), $e->click_param, $e->source_label ),
				)
			);
		}
		if ( '' !== $source && '' !== $host ) {
			$same = self::brand( $source ) === self::brand( $host ) || false !== strpos( $host, self::brand( $source ) );
			if ( $same ) {
				return array_merge(
					$out,
					array(
						'level'  => 'both',
						'label'  => __( 'Tags + browser agree', 'aarye-marketing-addon-for-forms' ),
						'status' => 'ok',
						/* translators: %s: website. */
						'text'   => sprintf( __( 'The tags in the address and the browser both say the visitor came from %s. This is the strongest proof a website can get for a visit (a determined bot can still fake both).', 'aarye-marketing-addon-for-forms' ), $host ),
					)
				);
			}
			return array_merge(
				$out,
				array(
					'level'  => 'mismatch',
					'label'  => __( 'Tags ≠ browser', 'aarye-marketing-addon-for-forms' ),
					'status' => 'warn',
					/* translators: 1: utm_source value, 2: referrer website. */
					'text'   => sprintf( __( 'The tags say %1$s, but the browser came from %2$s. The tagged link was probably copied and shared on another website, or the tags are wrong. The source shown follows the tags.', 'aarye-marketing-addon-for-forms' ), $source, $host ),
				)
			);
		}
		if ( '' !== $source ) {
			$referral = in_array( $medium, array( '', 'referral', 'ref', 'link', 'listing', 'directory', 'website' ), true ) && in_array( $e->channel, array( 'referral', 'organic_search', 'ai' ), true );
			return array_merge(
				$out,
				array(
					'level'  => 'tags',
					'label'  => __( 'Tags only', 'aarye-marketing-addon-for-forms' ),
					'status' => $referral ? 'warn' : 'info',
					'text'   => $referral
						/* translators: %s: utm_source value. */
						? sprintf( __( 'Only the tags in the address name %1$s (utm_source). The browser did not report coming from that website, although a real click on a link on another website normally does. Anyone can add these tags to a link, and spammers do it to look like a real referral. Check whether you are actually listed on %2$s before trusting it.', 'aarye-marketing-addon-for-forms' ), $source, $source )
						/* translators: %s: utm_source value. */
						: sprintf( __( 'Only the tags in the address name %s (utm_source). This is normal for e-mails, apps, QR codes and ads, which often hide the website they come from. Tags can be added to any link, so they are a claim, not a proof.', 'aarye-marketing-addon-for-forms' ), $source ),
				)
			);
		}
		if ( '' !== $host ) {
			return array_merge(
				$out,
				array(
					'level'  => 'referrer',
					'label'  => __( 'Browser referrer', 'aarye-marketing-addon-for-forms' ),
					'status' => 'ok',
					/* translators: %s: website. */
					'text'   => sprintf( __( 'The browser reported that the visitor clicked a link on %s. Browsers set this themselves; a web page cannot fake it, a bot can.', 'aarye-marketing-addon-for-forms' ), $host ),
				)
			);
		}
		if ( 'fbclid' === $e->click_param ) {
			return array_merge(
				$out,
				array(
					'level'  => 'fbclid',
					'label'  => __( 'Facebook click ID', 'aarye-marketing-addon-for-forms' ),
					'status' => 'ok',
					'text'   => __( 'The address carried fbclid, which Facebook and Instagram add to links clicked in their apps (ads and normal posts alike).', 'aarye-marketing-addon-for-forms' ),
				)
			);
		}
		return $out;
	}

	/**
	 * Words of a field that is meant to hold a web address.
	 *
	 * @param array $field Field.
	 * @return bool
	 */
	private static function is_url_field( $field ) {
		$text = strtolower( $field['type'] . ' ' . $field['name'] . ' ' . $field['label'] );
		return (bool) preg_match( '/url|website|web site|site|link|linkedin|portfolio/', $text );
	}

	/**
	 * Phrases common in sales spam sent through contact forms.
	 *
	 * @return string[]
	 */
	public static function spam_phrases() {
		/**
		 * Phrases that make the spam check doubtful (lower case).
		 *
		 * @param string[] $phrases Phrases.
		 */
		return (array) apply_filters(
			'aarye_spam_phrases',
			array( 'seo services', 'backlink', 'guest post', 'rank your website', 'first page of google', 'page 1 of google', 'increase your traffic', 'more traffic to your website', 'i noticed your website', 'i was checking your website', 'i came across your website', 'website is not ranking', 'crypto', 'bitcoin', 'forex', 'casino', 'viagra', 'payday loan', 'unsubscribe', 'reply stop', 'outsourcing', 'virtual assistant services', 'lead generation services', 'app development company' )
		);
	}

	/**
	 * Explain an entry: where it came from and whether a person sent it.
	 *
	 * @param object $e Entry.
	 * @return array{proof: array, checks: array, score: int, verdict: string, label: string, reasons: string[], details: array}
	 */
	public static function analyse( $e ) {
		$attr     = AARYE_Entries::json( $e, 'attribution' );
		$ev       = isset( $attr['evidence'] ) && is_array( $attr['evidence'] ) ? $attr['evidence'] : array();
		$ua       = isset( $attr['user_agent'] ) ? (string) $attr['user_agent'] : '';
		$proof    = self::proof( $e, $attr );
		$checks   = array();
		$add      = static function ( $key, $label, $value, $status, $text ) use ( &$checks ) {
			$checks[ $key ] = array(
				'label'  => $label,
				'value'  => $value,
				'status' => $status,
				'text'   => $text,
			);
		};
		$imported = ! empty( $attr['imported'] );
		$na       = __( 'Not recorded', 'aarye-marketing-addon-for-forms' );

		// Browser.
		if ( $imported && '' === $ua ) {
			$add( 'browser', __( 'Browser', 'aarye-marketing-addon-for-forms' ), $na, 'na', __( 'Imported entries have no browser details.', 'aarye-marketing-addon-for-forms' ) );
		} elseif ( $e->is_bot ) {
			$add( 'browser', __( 'Browser', 'aarye-marketing-addon-for-forms' ), '' !== $ua ? ( preg_match( '/headless\w*|bot|crawl\w*|spider|curl|wget|python[\w-]*|scrapy|phantom\w*|axios|okhttp|go-http[\w-]*|java/i', $ua, $m ) ? $m[0] : mb_substr( $ua, 0, 60 ) ) : __( 'empty', 'aarye-marketing-addon-for-forms' ), 'bad', __( 'The browser introduced itself as a bot or a script (or did not introduce itself at all). People\'s browsers don\'t do that.', 'aarye-marketing-addon-for-forms' ) );
		} else {
			$add( 'browser', __( 'Browser', 'aarye-marketing-addon-for-forms' ), ucfirst( $e->device ) . ( '' !== $e->browser ? ' · ' . $e->browser : '' ), 'ok', __( 'An ordinary browser. Bots can pretend to be one, so the checks below matter more.', 'aarye-marketing-addon-for-forms' ) );
		}

		if ( $ev ) {
			// Did our page script run at all?
			if ( empty( $ev['tracker'] ) ) {
				if ( ! empty( $ev['consent'] ) ) {
					$add( 'script', __( 'Page script', 'aarye-marketing-addon-for-forms' ), __( 'No cookie', 'aarye-marketing-addon-for-forms' ), 'info', __( 'The visitor did not accept marketing cookies, so the visit was not recorded. Nothing can be said about where they came from.', 'aarye-marketing-addon-for-forms' ) );
				} else {
					$add( 'script', __( 'Page script', 'aarye-marketing-addon-for-forms' ), __( 'Never ran', 'aarye-marketing-addon-for-forms' ), 'bad', __( 'The form was sent without the website ever running in a browser: no page of the site was opened before. This is how spam bots post forms directly. (A page cache serving an old page without the script can cause it too.)', 'aarye-marketing-addon-for-forms' ) );
				}
			} else {
				$add( 'script', __( 'Page script', 'aarye-marketing-addon-for-forms' ), __( 'Ran normally', 'aarye-marketing-addon-for-forms' ), 'ok', __( 'The visitor opened pages of the website in a browser before sending the form.', 'aarye-marketing-addon-for-forms' ) );
			}
			if ( null !== $ev['automation'] && $ev['automation'] ) {
				$add( 'automation', __( 'Automation', 'aarye-marketing-addon-for-forms' ), __( 'Controlled by software', 'aarye-marketing-addon-for-forms' ), 'bad', __( 'The browser reported that automation software (Selenium, Puppeteer, Playwright…) was controlling it.', 'aarye-marketing-addon-for-forms' ) );
			}
			if ( null !== $ev['interacted'] ) {
				if ( $ev['interacted'] ) {
					$add( 'interaction', __( 'Mouse, touch or keys', 'aarye-marketing-addon-for-forms' ), __( 'Yes', 'aarye-marketing-addon-for-forms' ), 'ok', __( 'The visitor moved the mouse, touched, scrolled or typed on the form page.', 'aarye-marketing-addon-for-forms' ) );
				} else {
					$add( 'interaction', __( 'Mouse, touch or keys', 'aarye-marketing-addon-for-forms' ), __( 'None', 'aarye-marketing-addon-for-forms' ), 'bad', __( 'Nobody moved the mouse, touched the screen, scrolled or pressed a key on the form page before it was sent. A person filling a form always does.', 'aarye-marketing-addon-for-forms' ) );
				}
			}
			if ( null !== $ev['seconds'] ) {
				$s    = (int) $ev['seconds'];
				$time = $s < 120 ? sprintf( /* translators: %d: seconds. */ _n( '%d second', '%d seconds', $s, 'aarye-marketing-addon-for-forms' ), $s ) : sprintf( /* translators: %d: minutes. */ _n( '%d minute', '%d minutes', (int) round( $s / 60 ), 'aarye-marketing-addon-for-forms' ), (int) round( $s / 60 ) );
				if ( $s < 3 ) {
					$add( 'speed', __( 'Time on the form page', 'aarye-marketing-addon-for-forms' ), $time, 'bad', __( 'Sent almost the moment the page opened: faster than anyone can read and type.', 'aarye-marketing-addon-for-forms' ) );
				} elseif ( $s < 8 ) {
					$add( 'speed', __( 'Time on the form page', 'aarye-marketing-addon-for-forms' ), $time, 'warn', __( 'Very quick. Possible with browser autofill, but typical of bots.', 'aarye-marketing-addon-for-forms' ) );
				} else {
					$add( 'speed', __( 'Time on the form page', 'aarye-marketing-addon-for-forms' ), $time, 'ok', __( 'A normal time to fill a form.', 'aarye-marketing-addon-for-forms' ) );
				}
			}
			$own = AARYE_Sources::is_own( (string) $ev['post_ref'] );
			if ( '' === (string) $ev['post_ref'] ) {
				$add( 'sent_from', __( 'Submission sent from', 'aarye-marketing-addon-for-forms' ), __( 'Not reported', 'aarye-marketing-addon-for-forms' ), 'warn', __( 'The browser did not say which page sent the form. Some privacy tools hide it; bots that post directly usually have none.', 'aarye-marketing-addon-for-forms' ) );
			} elseif ( ! $own ) {
				$add( 'sent_from', __( 'Submission sent from', 'aarye-marketing-addon-for-forms' ), self::host( $ev['post_ref'] ), 'bad', __( 'The form was submitted from another website, not from a page of yours.', 'aarye-marketing-addon-for-forms' ) );
			} else {
				$add( 'sent_from', __( 'Submission sent from', 'aarye-marketing-addon-for-forms' ), (string) wp_parse_url( $ev['post_ref'], PHP_URL_PATH ), 'ok', __( 'Sent from a page of your website, as expected.', 'aarye-marketing-addon-for-forms' ) );
			}
			if ( null !== $ev['pages'] ) {
				/* translators: %1$d: pages; %d: visits. */
				$add( 'pages', __( 'Pages viewed', 'aarye-marketing-addon-for-forms' ), sprintf( _n( '%1$d page', '%1$d pages', (int) $ev['pages'], 'aarye-marketing-addon-for-forms' ), (int) $ev['pages'] ) . ' · ' . sprintf( _n( '%d visit', '%d visits', max( 1, (int) $e->visits ), 'aarye-marketing-addon-for-forms' ), max( 1, (int) $e->visits ) ) $e->visits ) ), 'info', (int) $ev['pages'] <= 1 ? __( 'Opened only the page with the form. Normal for ad landing pages; also typical of spam.', 'aarye-marketing-addon-for-forms' ) : __( 'Looked around the website before sending.', 'aarye-marketing-addon-for-forms' ) );
			}
			if ( ! empty( $ev['repeat'] ) ) {
				/* translators: %d: number of entries. */
				$add( 'repeat', __( 'Repeated', 'aarye-marketing-addon-for-forms' ), sprintf( _n( '%d earlier entry in the hour before', '%d earlier entries in the hour before', (int) $ev['repeat'], 'aarye-marketing-addon-for-forms' ), (int) $ev['repeat'] ), (int) $ev['repeat'] >= 2 ? 'bad' : 'warn', __( 'The same email address or browser sent other entries shortly before.', 'aarye-marketing-addon-for-forms' ) );
			}
		} elseif ( ! $imported ) {
			$add( 'script', __( 'Visit checks', 'aarye-marketing-addon-for-forms' ), $na, 'na', __( 'Not recorded for this entry: it was saved before Aarye 1.1 added the visit checks (page script, mouse and keys, time on the page, where the submission came from), or it came from another service.', 'aarye-marketing-addon-for-forms' ) );
		}

		// What was written.
		$links  = 0;
		$text   = '';
		$fields = AARYE_Entries::json( $e, 'fields' );
		foreach ( $fields as $field ) {
			$value = (string) $field['value'];
			$text .= ' ' . strtolower( $value );
			if ( ! self::is_url_field( $field ) ) {
				$links += preg_match_all( '#(https?://|www\.|\[url|<a\s)#i', $value );
			}
		}
		if ( $links >= 3 ) {
			/* translators: %d: number of links. */
			$add( 'links', __( 'Links in the message', 'aarye-marketing-addon-for-forms' ), sprintf( __( '%d links', 'aarye-marketing-addon-for-forms' ), $links ), 'bad', __( 'Several links in a contact form message is a classic sign of spam.', 'aarye-marketing-addon-for-forms' ) );
		} elseif ( $links > 0 ) {
			/* translators: %d: number of links. */
			$add( 'links', __( 'Links in the message', 'aarye-marketing-addon-for-forms' ), sprintf( _n( '%d link', '%d links', $links, 'aarye-marketing-addon-for-forms' ), $links ), 'warn', __( 'People sometimes paste a link; spammers almost always do.', 'aarye-marketing-addon-for-forms' ) );
		}
		$found = array();
		foreach ( self::spam_phrases() as $phrase ) {
			if ( '' !== $phrase && false !== strpos( $text, strtolower( $phrase ) ) ) {
				$found[] = $phrase;
			}
		}
		if ( $found ) {
			$add( 'phrases', __( 'Sales-pitch words', 'aarye-marketing-addon-for-forms' ), '"' . implode( '", "', array_slice( $found, 0, 4 ) ) . '"', count( $found ) >= 2 ? 'bad' : 'warn', __( 'Words often used in unsolicited sales messages sent through contact forms.', 'aarye-marketing-addon-for-forms' ) );
		}

		// The source itself.
		if ( in_array( $proof['level'], array( 'tags', 'mismatch' ), true ) ) {
			$add( 'source', __( 'Source', 'aarye-marketing-addon-for-forms' ), $proof['label'], $proof['status'], $proof['text'] );
		}

		$score   = 0;
		$reasons = array();
		foreach ( $checks as $check ) {
			if ( 'bad' === $check['status'] ) {
				$score    += self::BAD;
				$reasons[] = $check['label'] . ': ' . $check['value'];
			} elseif ( 'warn' === $check['status'] ) {
				$score    += self::WARN;
				$reasons[] = $check['label'] . ': ' . $check['value'];
			}
		}
		$score   = min( 100, $score );
		$verdict = $score >= 60 ? 'spam' : ( $score >= 25 ? 'doubt' : 'ok' );
		$labels  = self::verdicts();

		$details = array();
		if ( '' !== $ua ) {
			$details[ __( 'Browser identification (user agent)', 'aarye-marketing-addon-for-forms' ) ] = $ua;
		}
		if ( $ev ) {
			$details[ __( 'Browser languages', 'aarye-marketing-addon-for-forms' ) ]   = (string) $ev['accept_lang'];
			$details[ __( 'Clock time zone', 'aarye-marketing-addon-for-forms' ) ]     = (string) $ev['timezone'];
			$details[ __( 'Screen', 'aarye-marketing-addon-for-forms' ) ]              = str_replace( 'x', ' × ', (string) $ev['screen'] );
			$details[ __( 'Submission referrer', 'aarye-marketing-addon-for-forms' ) ] = (string) $ev['post_ref'];
		}

		/**
		 * Change the evidence of an entry.
		 *
		 * @param array  $result Result.
		 * @param object $e      Entry.
		 */
		return (array) apply_filters(
			'aarye_evidence',
			array(
				'proof'   => $proof,
				'checks'  => $checks,
				'score'   => $score,
				'verdict' => $verdict,
				'label'   => $labels[ $verdict ],
				'reasons' => $reasons,
				'details' => array_filter( $details, 'strlen' ),
			),
			$e
		);
	}

	/**
	 * Names of the spam check results.
	 *
	 * @return array<string, string>
	 */
	public static function verdicts() {
		return array(
			'ok'    => __( 'Looks genuine', 'aarye-marketing-addon-for-forms' ),
			'doubt' => __( 'Some doubts', 'aarye-marketing-addon-for-forms' ),
			'spam'  => __( 'Likely spam', 'aarye-marketing-addon-for-forms' ),
		);
	}
}
