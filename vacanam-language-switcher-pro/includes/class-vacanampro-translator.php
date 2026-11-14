<?php
/**
 * Translating content with the chosen service.
 *
 * Text is taken out of pages and posts (title, excerpt, blocks or classic content, Elementor
 * designs, SEO texts), categories, menus and the free plugin's texts as segments: a sentence keeps
 * its inline formatting and links, so it is translated as a whole. Every translation is remembered
 * (translation memory), so nothing is paid for twice, a large page is finished over several short
 * requests, and later only changed sentences go to the service again.
 *
 * @package VacanamPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * Translator.
 */
class VACANAMPRO_Translator {

	const DB_VERSION = '1';
	const BATCH      = 40;
	const BATCH_SIZE = 12000;

	/**
	 * Inline HTML elements that stay inside a sentence.
	 *
	 * @var string[]
	 */
	private static $inline = array( 'a', 'abbr', 'b', 'bdi', 'bdo', 'br', 'cite', 'code', 'data', 'dfn', 'em', 'i', 'kbd', 'mark', 'q', 's', 'samp', 'small', 'span', 'strong', 'sub', 'sup', 'time', 'u', 'var', 'wbr', 'font', 'del', 'ins' );

	/**
	 * Attributes whose text is translated.
	 *
	 * @var string[]
	 */
	private static $attributes = array( 'alt', 'title', 'placeholder', 'aria-label', 'data-title', 'value' );

	/**
	 * SEO texts of the supported SEO plugins.
	 *
	 * @var string[]
	 */
	private static $seo_meta = array( '_yoast_wpseo_title', '_yoast_wpseo_metadesc', '_yoast_wpseo_focuskw', '_yoast_wpseo_opengraph-title', '_yoast_wpseo_opengraph-description', '_yoast_wpseo_twitter-title', '_yoast_wpseo_twitter-description', 'rank_math_title', 'rank_math_description', 'rank_math_focus_keyword', 'rank_math_facebook_title', 'rank_math_facebook_description', 'rank_math_twitter_title', 'rank_math_twitter_description', '_seopress_titles_title', '_seopress_titles_desc', '_seopress_analysis_target_kw', '_seopress_social_fb_title', '_seopress_social_fb_desc' );

	/**
	 * Elementor settings that always hold text (others are translated when they look like text).
	 *
	 * @var string[]
	 */
	private static $elementor_text = array( 'title', 'editor', 'text', 'description', 'description_text', 'title_text', 'button_text', 'heading', 'sub_heading', 'subheading', 'caption', 'tab_title', 'tab_content', 'testimonial_content', 'testimonial_name', 'testimonial_job', 'label', 'field_label', 'placeholder', 'alert_title', 'alert_description', 'prefix', 'suffix', 'before_text', 'after_text', 'highlighted_text', 'rotating_text', 'inner_text', 'price', 'period', 'item_text', 'list_title', 'list_content', 'link_text', 'read_more_text', 'html', 'acf_label', 'success_message', 'error_message', 'required_field_message', 'invalid_message' );

	/**
	 * Elementor settings that never hold text.
	 *
	 * @var string[]
	 */
	private static $elementor_skip = array( 'id', 'elType', 'widgetType', 'url', 'link', '_id', 'id_name', 'image', 'background_image', 'icon', 'selected_icon', 'css_classes', '_css_classes', '_element_id', 'html_tag', 'header_size', 'align', 'size', 'shape', 'view', 'layout', 'skin', 'custom_css', 'template_id', 'form_id', 'shortcode', 'video_type', 'youtube_url', 'vimeo_url', 'external_url', 'hosted_url', 'library', 'value', 'unit', 'source' );

	/*
	 * Translation memory.
	 */

	/**
	 * Table name.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'vacanampro_memory';
	}

	/**
	 * Create the table when needed.
	 */
	public static function install() {
		if ( get_option( 'vacanampro_memory_db' ) === self::DB_VERSION ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				hash char(32) NOT NULL,
				lang varchar(20) NOT NULL DEFAULT '',
				source longtext NOT NULL,
				target longtext NOT NULL,
				provider varchar(40) NOT NULL DEFAULT '',
				created datetime NOT NULL,
				PRIMARY KEY  (hash),
				KEY lang (lang)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'vacanampro_memory_db', self::DB_VERSION, false );
	}

	/**
	 * Memory key of a segment in a language.
	 *
	 * @param string $from   Source language.
	 * @param string $to     Target language.
	 * @param string $source Text.
	 * @return string
	 */
	private static function hash( $from, $to, $source ) {
		return md5( $from . '>' . $to . '|' . $source );
	}

	/**
	 * Remembered translations of some segments.
	 *
	 * @param string[] $segments Texts.
	 * @param string   $from     Source language.
	 * @param string   $to       Target language.
	 * @return array text => translation.
	 */
	public static function remembered( $segments, $from, $to ) {
		global $wpdb;
		$out = array();
		foreach ( array_chunk( array_values( array_unique( $segments ) ), 200 ) as $chunk ) {
			$hashes = array();
			foreach ( $chunk as $text ) {
				$hashes[ self::hash( $from, $to, $text ) ] = $text;
			}
			$in = implode( ',', array_fill( 0, count( $hashes ), '%s' ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare -- Placeholders built above.
			$rows = $wpdb->get_results( $wpdb->prepare( "SELECT hash, target FROM %i WHERE hash IN ($in)", array_merge( array( self::table() ), array_keys( $hashes ) ) ) );
			foreach ( (array) $rows as $row ) {
				$out[ $hashes[ $row->hash ] ] = $row->target;
			}
		}
		return $out;
	}

	/**
	 * Remember translations.
	 *
	 * @param array  $pairs    text => translation.
	 * @param string $from     Source language.
	 * @param string $to       Target language.
	 * @param string $provider Service.
	 */
	private static function remember( $pairs, $from, $to, $provider ) {
		global $wpdb;
		foreach ( $pairs as $source => $target ) {
			$wpdb->replace( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
				self::table(),
				array(
					'hash'     => self::hash( $from, $to, (string) $source ),
					'lang'     => $to,
					'source'   => (string) $source,
					'target'   => (string) $target,
					'provider' => $provider,
					'created'  => current_time( 'mysql', true ),
				)
			);
		}
	}

	/*
	 * Segments.
	 */

	/**
	 * Whether a text has something to translate.
	 *
	 * @param string $text Text.
	 * @return bool
	 */
	public static function has_words( $text ) {
		$plain = trim( html_entity_decode( wp_strip_all_tags( (string) $text ), ENT_QUOTES, 'UTF-8' ) );
		if ( '' === $plain || ! preg_match( '/\p{L}/u', $plain ) ) {
			return false;
		}
		// Shortcodes, placeholders, addresses and e-mails alone are not text.
		$rest = preg_replace( '/\[[^\]]*\]|%%[^%]+%%|\{\{?[^}]+\}\}?|https?:\/\/\S+|\S+@\S+\.\S+/u', '', $plain );
		return (bool) preg_match( '/\p{L}/u', (string) $rest );
	}

	/**
	 * Walk the sentences of some HTML: collect them, or replace them with translations.
	 *
	 * @param string     $html    HTML.
	 * @param array|null $map     text => translation (null to collect).
	 * @param array      $collect Segments found (when collecting).
	 * @return string
	 */
	public static function html( $html, $map, &$collect ) {
		$html = (string) $html;
		if ( '' === trim( $html ) ) {
			return $html;
		}
		if ( false === strpos( $html, '<' ) ) {
			return self::segment( $html, $map, $collect );
		}
		$tokens = preg_split( '/(<!--.*?-->|<[^>]+>)/s', $html, -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY );
		$out    = '';
		$run    = '';
		$skip   = '';
		foreach ( $tokens as $token ) {
			if ( '' !== $skip ) {
				$out .= $token;
				if ( preg_match( '#^</' . $skip . '\s*>#i', $token ) ) {
					$skip = '';
				}
				continue;
			}
			if ( '<' !== $token[0] ) {
				$run .= $token;
				continue;
			}
			if ( 0 === strpos( $token, '<!--' ) ) {
				$out .= self::segment( $run, $map, $collect ) . $token;
				$run  = '';
				continue;
			}
			$name = strtolower( (string) preg_replace( '#^</?([a-z0-9-]+).*$#is', '$1', $token ) );
			$tag  = self::attributes( $token, $map, $collect );
			if ( in_array( $name, self::$inline, true ) ) {
				$run .= $tag;
				continue;
			}
			$out .= self::segment( $run, $map, $collect ) . $tag;
			$run  = '';
			if ( in_array( $name, array( 'script', 'style', 'code', 'pre', 'svg', 'textarea', 'noscript' ), true ) && '/' !== $token[1] && '/>' !== substr( $token, -2 ) ) {
				$skip = $name;
			}
		}
		return $out . self::segment( $run, $map, $collect );
	}

	/**
	 * One sentence (text with inline tags): collect or replace, keeping the spaces around it.
	 *
	 * @param string     $run     Text.
	 * @param array|null $map     Translations.
	 * @param array      $collect Segments.
	 * @return string
	 */
	private static function segment( $run, $map, &$collect ) {
		if ( ! self::has_words( $run ) ) {
			return $run;
		}
		preg_match( '/^(\s*)(.*?)(\s*)$/su', $run, $m );
		$core = $m[2];
		// Unbalanced inline tags at the edges stay outside (for example a closing </a> of an earlier link).
		if ( null === $map ) {
			$collect[] = $core;
			return $run;
		}
		return isset( $map[ $core ] ) ? $m[1] . $map[ $core ] . $m[3] : $run;
	}

	/**
	 * Text attributes of a tag (alt, title, placeholder…).
	 *
	 * @param string     $tag     Tag.
	 * @param array|null $map     Translations.
	 * @param array      $collect Segments.
	 * @return string
	 */
	private static function attributes( $tag, $map, &$collect ) {
		if ( '/' === $tag[1] || false === strpos( $tag, '=' ) ) {
			return $tag;
		}
		$out = preg_replace_callback(
			'/(\s(' . implode( '|', array_map( 'preg_quote', self::$attributes ) ) . ')=)(["\'])(.*?)\3/i',
			static function ( $m ) use ( $map, &$collect ) {
				if ( 'value' === strtolower( $m[2] ) && ! preg_match( '/type=["\']?(submit|button|reset)/i', $m[0] ) ) {
					return $m[0];
				}
				$text = html_entity_decode( $m[4], ENT_QUOTES, 'UTF-8' );
				if ( ! self::has_words( $text ) ) {
					return $m[0];
				}
				if ( null === $map ) {
					$collect[] = $text;
					return $m[0];
				}
				return isset( $map[ $text ] ) ? $m[1] . $m[3] . esc_attr( $map[ $text ] ) . $m[3] : $m[0];
			},
			$tag
		);
		return null === $out ? $tag : $out;
	}

	/**
	 * Walk Elementor data.
	 *
	 * @param mixed      $data    Decoded data.
	 * @param array|null $map     Translations.
	 * @param array      $collect Segments.
	 * @param string     $key     Key of this value.
	 * @return mixed
	 */
	public static function elementor( $data, $map, &$collect, $key = '' ) {
		if ( is_array( $data ) ) {
			foreach ( $data as $k => $value ) {
				if ( is_string( $k ) && in_array( $k, self::$elementor_skip, true ) ) {
					continue;
				}
				$data[ $k ] = self::elementor( $value, $map, $collect, is_string( $k ) ? $k : $key );
			}
			return $data;
		}
		if ( ! is_string( $data ) || '' === trim( $data ) || preg_match( '#^(https?:)?//|^\##i', $data ) ) {
			return $data;
		}
		$known = in_array( $key, self::$elementor_text, true );
		if ( ! $known && ( ! preg_match( '/\s/u', trim( $data ) ) || preg_match( '/^[a-z0-9_-]+$/i', $data ) ) ) {
			return $data; // A setting value (left, solid, h2…), not text.
		}
		return self::html( $data, $map, $collect );
	}

	/*
	 * Translating.
	 */

	/**
	 * Context for the service: tone, variant, glossary.
	 *
	 * @param string   $to       Target language.
	 * @param string[] $segments Segments (to send only the glossary lines they use).
	 * @return array
	 */
	public static function context( $to, $segments ) {
		$context = array(
			'tone'  => (string) VACANAMPRO_Settings::get( 'ai_tone' ),
			'about' => (string) VACANAMPRO_Settings::get( 'ai_about' ),
			'keep'  => array(),
		);
		$tones = array(
			'formal'   => 'formal and professional',
			'friendly' => 'friendly and warm, addressing the reader directly',
			'neutral'  => '',
		);
		$context['tone'] = isset( $tones[ $context['tone'] ] ) ? $tones[ $context['tone'] ] : '';
		if ( 'ar' === substr( $to, 0, 2 ) && 'gulf' === VACANAMPRO_Settings::get( 'ai_arabic' ) ) {
			$context['variant'] = 'Write Modern Standard Arabic that reads naturally for readers in the Gulf (UAE, Saudi Arabia, Qatar, Kuwait, Bahrain, Oman).';
		}
		if ( 'formal' === VACANAMPRO_Settings::get( 'ai_tone' ) ) {
			$context['formality'] = 'prefer_more';
		} elseif ( 'friendly' === VACANAMPRO_Settings::get( 'ai_tone' ) ) {
			$context['formality'] = 'prefer_less';
		}
		$all   = implode( "\n", $segments );
		$lines = array();
		foreach ( preg_split( '/\r\n|\r|\n/', (string) VACANAMPRO_Settings::get( 'ai_glossary' ) ) as $line ) {
			$line = trim( $line );
			if ( '' === $line ) {
				continue;
			}
			$term = trim( (string) preg_replace( '/\s*(=>|=).*$/u', '', $line ) );
			if ( '' === $term || false === mb_stripos( $all, $term ) ) {
				continue;
			}
			if ( ! preg_match( '/=>|=/', $line ) ) {
				$lines[]           = '- "' . $term . '": never translate, keep as written.';
				$context['keep'][] = $term;
				continue;
			}
			// "term => ar: ترجمة, fr: traduction" or "term = translation" (all languages).
			$rest  = trim( (string) preg_replace( '/^.*?(=>|=)\s*/u', '', $line ) );
			$fixed = '';
			if ( preg_match( '/(?:^|,)\s*' . preg_quote( $to, '/' ) . '\s*:\s*([^,]+)/u', $rest, $m ) ) {
				$fixed = trim( $m[1] );
			} elseif ( ! preg_match( '/^[a-z]{2,5}\s*:/i', $rest ) ) {
				$fixed = $rest;
			}
			if ( '' !== $fixed ) {
				$lines[] = '- "' . $term . '": always translate as "' . $fixed . '".';
			}
		}
		$context['glossary'] = implode( "\n", $lines );
		return $context;
	}

	/**
	 * Translate segments that are not remembered yet, up to a number of requests.
	 *
	 * @param string[] $segments Segments.
	 * @param string   $from     Source language.
	 * @param string   $to       Target language.
	 * @param int      $requests Most requests to make now.
	 * @return array|WP_Error { map: text => translation, left: segments still to translate }
	 */
	public static function translate_segments( $segments, $from, $to, $requests = 3 ) {
		$segments = array_values( array_unique( array_filter( $segments, 'strlen' ) ) );
		$map      = self::remembered( $segments, $from, $to );
		$todo     = array_values( array_diff( $segments, array_keys( $map ) ) );
		$langs    = Vacanam_Languages::all();
		if ( ! isset( $langs[ $from ], $langs[ $to ] ) ) {
			return new WP_Error( 'vacanampro_lang', __( 'Unknown language.', 'vacanam-language-switcher-pro' ) );
		}
		$batches = array();
		$batch   = array();
		$size    = 0;
		foreach ( $todo as $text ) {
			if ( $batch && ( count( $batch ) >= self::BATCH || $size + strlen( $text ) > self::BATCH_SIZE ) ) {
				$batches[] = $batch;
				$batch     = array();
				$size      = 0;
			}
			$batch[] = $text;
			$size   += strlen( $text );
		}
		if ( $batch ) {
			$batches[] = $batch;
		}
		$use = VACANAMPRO_AI::chosen();
		foreach ( array_slice( $batches, 0, max( 1, (int) $requests ) ) as $batch ) {
			$result = VACANAMPRO_AI::translate( $batch, $langs[ $from ], $langs[ $to ], self::context( $to, $batch ) );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
			$pairs = array_combine( $batch, $result );
			self::remember( $pairs, $from, $to, $use['provider'] . ':' . $use['model'] );
			$map = array_merge( $map, $pairs );
		}
		return array(
			'map'  => $map,
			'left' => array_values( array_diff( $segments, array_keys( $map ) ) ),
		);
	}

	/**
	 * Source of a translation: the same content in the main language, or else in any language.
	 *
	 * @param int $post_id Translation.
	 * @return int
	 */
	public static function source_post( $post_id ) {
		$lang  = Vacanam_Model::post_lang( $post_id );
		$group = Vacanam_Model::post_translations( $post_id );
		$main  = Vacanam_Languages::default_slug();
		if ( $lang !== $main && ! empty( $group[ $main ] ) ) {
			return (int) $group[ $main ];
		}
		foreach ( $group as $slug => $id ) {
			if ( $slug !== $lang ) {
				return (int) $id;
			}
		}
		return 0;
	}

	/**
	 * Translate a post from its source (one step: returns 'more' when it must be called again).
	 *
	 * @param int $post_id  Translation to fill.
	 * @param int $requests Requests allowed in this step.
	 * @return array|WP_Error { status: done|more, left: int }
	 */
	public static function translate_post( $post_id, $requests = 3 ) {
		$post   = get_post( $post_id );
		$source = $post ? get_post( self::source_post( $post_id ) ) : null;
		$to     = $post ? Vacanam_Model::post_lang( $post ) : '';
		$from   = $source ? Vacanam_Model::post_lang( $source ) : '';
		if ( ! $post || ! $source || '' === $to || '' === $from ) {
			return new WP_Error( 'vacanampro_source', __( 'This page has no original in another language to translate from.', 'vacanam-language-switcher-pro' ) );
		}
		$parts    = self::post_parts( $source );
		$segments = self::collect( $parts );
		$result   = self::translate_segments( $segments, $from, $to, $requests );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( $result['left'] ) {
			return array(
				'status' => 'more',
				'left'   => count( $result['left'] ),
			);
		}
		self::apply_post( $post, $parts, $result['map'] );
		update_post_meta( $post->ID, '_vacanampro_translated', array( 'time' => time(), 'from' => $source->ID, 'hash' => self::parts_hash( $parts ) ) );
		/**
		 * A post was translated with the translation service.
		 *
		 * @param int $post_id Translation.
		 * @param int $source  Original.
		 */
		do_action( 'vacanampro_post_translated', $post->ID, $source->ID );
		return array(
			'status' => 'done',
			'left'   => 0,
		);
	}

	/**
	 * The translatable parts of a post.
	 *
	 * @param WP_Post $post Post.
	 * @return array
	 */
	public static function post_parts( $post ) {
		$parts = array(
			'title'   => $post->post_title,
			'excerpt' => $post->post_excerpt,
			'content' => $post->post_content,
			'meta'    => array(),
		);
		foreach ( self::$seo_meta as $key ) {
			$value = get_post_meta( $post->ID, $key, true );
			if ( is_string( $value ) && '' !== $value ) {
				$parts['meta'][ $key ] = $value;
			}
		}
		$elementor = get_post_meta( $post->ID, '_elementor_data', true );
		if ( is_string( $elementor ) && '' !== $elementor ) {
			$decoded = json_decode( $elementor, true );
			if ( is_array( $decoded ) ) {
				$parts['elementor'] = $decoded;
			}
		}
		if ( 'attachment' === $post->post_type ) {
			$alt = get_post_meta( $post->ID, '_wp_attachment_image_alt', true );
			if ( '' !== (string) $alt ) {
				$parts['meta']['_wp_attachment_image_alt'] = $alt;
			}
		}
		/**
		 * Parts of a post to translate (add-ons add their own meta texts to 'meta').
		 *
		 * @param array   $parts Parts.
		 * @param WP_Post $post  Original post.
		 */
		return (array) apply_filters( 'vacanampro_post_parts', $parts, $post );
	}

	/**
	 * Fingerprint of the parts (to tell later whether the original changed).
	 *
	 * @param array $parts Parts.
	 * @return string
	 */
	public static function parts_hash( $parts ) {
		return md5( (string) wp_json_encode( $parts ) );
	}

	/**
	 * Segments of some parts.
	 *
	 * @param array $parts Parts.
	 * @return string[]
	 */
	public static function collect( $parts ) {
		$segments = array();
		foreach ( array( 'title', 'excerpt' ) as $field ) {
			if ( ! empty( $parts[ $field ] ) && self::has_words( $parts[ $field ] ) ) {
				$segments[] = trim( $parts[ $field ] );
			}
		}
		if ( ! empty( $parts['content'] ) ) {
			self::html( $parts['content'], null, $segments );
		}
		foreach ( (array) $parts['meta'] as $value ) {
			if ( self::has_words( $value ) ) {
				$segments[] = trim( $value );
			}
		}
		if ( ! empty( $parts['elementor'] ) ) {
			self::elementor( $parts['elementor'], null, $segments );
		}
		return array_values( array_unique( $segments ) );
	}

	/**
	 * Write the translated parts into the translation.
	 *
	 * @param WP_Post $post  Translation.
	 * @param array   $parts Parts of the original.
	 * @param array   $map   text => translation.
	 */
	private static function apply_post( $post, $parts, $map ) {
		$none   = array();
		$update = array( 'ID' => $post->ID );
		foreach ( array(
			'title'   => 'post_title',
			'excerpt' => 'post_excerpt',
		) as $part => $field ) {
			if ( ! empty( $parts[ $part ] ) ) {
				$key              = trim( $parts[ $part ] );
				$update[ $field ] = isset( $map[ $key ] ) ? $map[ $key ] : $parts[ $part ];
			}
		}
		if ( ! empty( $parts['content'] ) ) {
			$update['post_content'] = self::translate_own( $post->post_content, $parts['content'], $map );
		}
		// Keep the translation's own links to other translations: only text changes.
		wp_update_post( wp_slash( $update ) );
		foreach ( (array) $parts['meta'] as $key => $value ) {
			$t = trim( $value );
			update_post_meta( $post->ID, $key, wp_slash( isset( $map[ $t ] ) ? $map[ $t ] : $value ) );
		}
		if ( ! empty( $parts['elementor'] ) ) {
			$current = json_decode( (string) get_post_meta( $post->ID, '_elementor_data', true ), true );
			$data    = is_array( $current ) ? self::elementor_pair( $current, $parts['elementor'], $map ) : self::elementor( $parts['elementor'], $map, $none );
			update_post_meta( $post->ID, '_elementor_data', wp_slash( wp_json_encode( $data ) ) );
			if ( class_exists( '\Elementor\Plugin' ) && isset( \Elementor\Plugin::$instance->files_manager ) ) {
				\Elementor\Plugin::$instance->files_manager->clear_cache();
			}
		}
	}

	/**
	 * Translate the copy's own HTML (its links and images already point to its language) when its
	 * text is still the original's; otherwise translate the original again.
	 *
	 * @param string $own      The copy's value.
	 * @param string $original The original's value.
	 * @param array  $map      text => translation.
	 * @return string
	 */
	private static function translate_own( $own, $original, $map ) {
		$none  = array();
		$found = array();
		self::html( $own, null, $found );
		foreach ( $found as $segment ) {
			if ( ! isset( $map[ $segment ] ) ) {
				return self::html( $original, $map, $none );
			}
		}
		return self::html( $own, $map, $none );
	}

	/**
	 * Walk the copy's Elementor data next to the original's, translating each text.
	 *
	 * @param mixed  $own      Copy's data.
	 * @param mixed  $original Original's data at the same place.
	 * @param array  $map      Translations.
	 * @param string $key      Key.
	 * @return mixed
	 */
	private static function elementor_pair( $own, $original, $map, $key = '' ) {
		if ( is_array( $own ) ) {
			foreach ( $own as $k => $value ) {
				if ( is_string( $k ) && in_array( $k, self::$elementor_skip, true ) ) {
					continue;
				}
				$own[ $k ] = self::elementor_pair( $value, is_array( $original ) && isset( $original[ $k ] ) ? $original[ $k ] : $value, $map, is_string( $k ) ? $k : $key );
			}
			return $own;
		}
		if ( ! is_string( $own ) ) {
			return $own;
		}
		$probe = array();
		self::elementor( $own, null, $probe, $key );
		if ( ! $probe ) {
			return $own;
		}
		return self::translate_own( $own, is_string( $original ) ? $original : $own, $map );
	}

	/**
	 * Translate a term (name and description) from its source.
	 *
	 * @param int $term_id Translation.
	 * @return true|WP_Error
	 */
	public static function translate_term( $term_id ) {
		$term = get_term( $term_id );
		$to   = $term && ! is_wp_error( $term ) ? Vacanam_Model::term_lang( $term ) : '';
		if ( '' === $to ) {
			return new WP_Error( 'vacanampro_source', __( 'This term has no language.', 'vacanam-language-switcher-pro' ) );
		}
		$group  = Vacanam_Model::term_translations( $term_id );
		$main   = Vacanam_Languages::default_slug();
		$source = $to !== $main && ! empty( $group[ $main ] ) ? $group[ $main ] : 0;
		if ( ! $source ) {
			foreach ( $group as $slug => $id ) {
				if ( $slug !== $to ) {
					$source = $id;
					break;
				}
			}
		}
		$original = $source ? get_term( (int) $source ) : null;
		if ( ! $original || is_wp_error( $original ) ) {
			return new WP_Error( 'vacanampro_source', __( 'This term has no original in another language.', 'vacanam-language-switcher-pro' ) );
		}
		$from     = Vacanam_Model::term_lang( $original );
		$segments = array();
		if ( self::has_words( $original->name ) ) {
			$segments[] = trim( $original->name );
		}
		self::html( $original->description, null, $segments );
		$result = self::translate_segments( $segments, $from, $to, 5 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$none = array();
		$name = trim( $original->name );
		$updated = wp_update_term(
			$term->term_id,
			$term->taxonomy,
			array(
				'name'        => isset( $result['map'][ $name ] ) ? $result['map'][ $name ] : $original->name,
				'description' => self::html( $original->description, $result['map'], $none ),
			)
		);
		if ( is_wp_error( $updated ) ) {
			return $updated;
		}
		update_term_meta( $term->term_id, '_vacanampro_translated', time() );
		return true;
	}

	/**
	 * Translate the custom titles of a menu (items that link to pages follow the page's title).
	 *
	 * @param int $menu_id Menu of a language.
	 * @return true|WP_Error
	 */
	public static function translate_menu( $menu_id ) {
		$to      = Vacanam_Menus::menu_lang( $menu_id );
		$main    = Vacanam_Languages::default_slug();
		$source  = Vacanam_Menus::translation( $menu_id, $main );
		$from    = $main;
		$items   = wp_get_nav_menu_items( $menu_id );
		$s_items = $source ? wp_get_nav_menu_items( $source ) : array();
		if ( '' === $to || $to === $main || ! $items || ! $s_items ) {
			return new WP_Error( 'vacanampro_source', __( 'This menu has no original in the main language.', 'vacanam-language-switcher-pro' ) );
		}
		$segments = array();
		foreach ( $s_items as $item ) {
			if ( self::has_words( $item->post_title ) ) {
				$segments[] = trim( $item->post_title );
			}
		}
		$result = self::translate_segments( $segments, $from, $to, 3 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		// Items are matched in menu order (the copy has the same structure as the original).
		foreach ( array_values( $items ) as $i => $item ) {
			$orig = isset( $s_items[ $i ] ) ? trim( $s_items[ $i ]->post_title ) : '';
			if ( '' !== $orig && isset( $result['map'][ $orig ] ) && trim( $item->post_title ) === $orig ) {
				wp_update_post(
					array(
						'ID'         => $item->ID,
						'post_title' => $result['map'][ $orig ],
					)
				);
			}
		}
		return true;
	}

	/**
	 * Translate the free plugin's texts (site title, tagline, widgets, theme texts) that have no
	 * translation yet in a language.
	 *
	 * @param string $to Language.
	 * @return int|WP_Error Number translated.
	 */
	public static function translate_strings( $to ) {
		$main = Vacanam_Languages::default_slug();
		if ( $to === $main ) {
			return 0;
		}
		$saved = Vacanam_Strings::translations();
		$todo  = array();
		foreach ( Vacanam_Strings::registered() as $key => $info ) {
			$text = isset( $info['string'] ) ? (string) $info['string'] : '';
			$name = isset( $info['name'] ) ? (string) $info['name'] : '';
			// Date and time formats are codes (F j, Y), not words.
			if ( in_array( $name, array( __( 'Date format', 'vacanam-language-switcher' ), __( 'Time format', 'vacanam-language-switcher' ) ), true ) ) { // phpcs:ignore WordPress.WP.I18n.TextDomainMismatch -- The free plugin's own labels.
				continue;
			}
			if ( '' !== $text && empty( $saved[ $to ][ $key ] ) && self::has_words( $text ) ) {
				$todo[ $key ] = $text;
			}
		}
		if ( ! $todo ) {
			return 0;
		}
		$result = self::translate_segments( array_values( $todo ), $main, $to, 10 );
		if ( is_wp_error( $result ) ) {
			return $result;
		}
		$items = array();
		foreach ( $todo as $key => $text ) {
			if ( isset( $result['map'][ $text ] ) ) {
				$items[ $key ] = $result['map'][ $text ];
			}
		}
		Vacanam_Strings::save( $to, $items );
		return count( $items );
	}
}
