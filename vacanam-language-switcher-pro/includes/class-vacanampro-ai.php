<?php
/**
 * Translation services: Anthropic (Claude), OpenAI (ChatGPT), Google (Gemini) and DeepL, with the
 * site owner's own API keys. Text goes out as a list of segments and comes back as a list of the
 * same length. OpenAI and Google can also redraw an image with its text in another language.
 *
 * Keys are stored encrypted (AES-256-GCM with this site's salts) and never shown again.
 *
 * @package VacanamPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * AI and DeepL connectors.
 */
class VACANAMPRO_AI {

	const ENC = 'vpenc:v1:';

	/**
	 * Services that translate text: id => label.
	 *
	 * @return array
	 */
	public static function providers() {
		return array(
			'anthropic' => 'Anthropic (Claude)',
			'openai'    => 'OpenAI (ChatGPT)',
			'google'    => 'Google (Gemini)',
			'deepl'     => 'DeepL',
		);
	}

	/**
	 * Models offered per service (the first is the default; any other model name can be typed).
	 *
	 * @param string $provider Service.
	 * @return string[]
	 */
	public static function models( $provider ) {
		$all = array(
			'anthropic' => array( 'claude-opus-5-5', 'claude-sonnet-5-5', 'claude-haiku-5-5' ),
			'openai'    => array( 'gpt-5-mini', 'gpt-5', 'gpt-4.1-mini' ),
			'google'    => array( 'gemini-2.5-flash', 'gemini-2.5-pro' ),
			'deepl'     => array(),
		);
		return isset( $all[ $provider ] ) ? $all[ $provider ] : array();
	}

	/*
	 * Keys.
	 */

	/**
	 * Encryption key from this site's salts.
	 *
	 * @return string
	 */
	private static function cipher_key() {
		return hash_hmac( 'sha256', 'vacanam-language-switcher-pro|keys', wp_salt( 'auth' ), true );
	}

	/**
	 * Encrypt a key for storage.
	 *
	 * @param string $plain Key.
	 * @return string
	 */
	public static function encrypt( $plain ) {
		$plain = (string) $plain;
		if ( '' === $plain || ! function_exists( 'openssl_encrypt' ) ) {
			return $plain;
		}
		$iv     = random_bytes( 12 );
		$tag    = '';
		$cipher = openssl_encrypt( $plain, 'aes-256-gcm', self::cipher_key(), OPENSSL_RAW_DATA, $iv, $tag );
		return false === $cipher ? $plain : self::ENC . base64_encode( $iv . $tag . $cipher ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Storage encoding of encrypted bytes.
	}

	/**
	 * Decrypt a stored key.
	 *
	 * @param string $stored Stored value.
	 * @return string
	 */
	public static function decrypt( $stored ) {
		$stored = (string) $stored;
		if ( 0 !== strpos( $stored, self::ENC ) ) {
			return $stored;
		}
		$raw = base64_decode( substr( $stored, strlen( self::ENC ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Storage encoding of encrypted bytes.
		if ( false === $raw || strlen( $raw ) < 29 ) {
			return '';
		}
		$plain = openssl_decrypt( substr( $raw, 28 ), 'aes-256-gcm', self::cipher_key(), OPENSSL_RAW_DATA, substr( $raw, 0, 12 ), substr( $raw, 12, 16 ) );
		return false === $plain ? '' : $plain;
	}

	/**
	 * API key of a service.
	 *
	 * @param string $provider Service.
	 * @return string
	 */
	public static function key( $provider ) {
		$keys = (array) VACANAMPRO_Settings::get( 'ai_keys' );
		return isset( $keys[ $provider ] ) ? trim( self::decrypt( $keys[ $provider ] ) ) : '';
	}

	/**
	 * Service and model chosen for text.
	 *
	 * @return array { provider, model }
	 */
	public static function chosen() {
		$provider = (string) VACANAMPRO_Settings::get( 'ai_provider' );
		$provider = isset( self::providers()[ $provider ] ) ? $provider : 'anthropic';
		$models   = (array) VACANAMPRO_Settings::get( 'ai_models' );
		$model    = isset( $models[ $provider ] ) && '' !== $models[ $provider ] ? $models[ $provider ] : (string) current( self::models( $provider ) );
		return array(
			'provider' => $provider,
			'model'    => $model,
		);
	}

	/**
	 * Whether a text service is ready.
	 *
	 * @return bool
	 */
	public static function ready() {
		return '' !== self::key( self::chosen()['provider'] );
	}

	/*
	 * Text.
	 */

	/**
	 * Translate a list of segments.
	 *
	 * @param string[] $segments Texts (may contain inline HTML).
	 * @param array    $from     Source language (Vacanam language array).
	 * @param array    $to       Target language.
	 * @param array    $context  glossary => text, tone => text, about => text.
	 * @return string[]|WP_Error Same count and order.
	 */
	public static function translate( $segments, $from, $to, $context = array() ) {
		$segments = array_values( array_map( 'strval', $segments ) );
		if ( ! $segments ) {
			return array();
		}
		$use = self::chosen();
		$key = self::key( $use['provider'] );
		if ( '' === $key ) {
			return new WP_Error( 'vacanampro_ai_key', __( 'Add an API key for the translation service in Languages > Pro.', 'vacanam-language-switcher-pro' ) );
		}
		if ( 'deepl' === $use['provider'] ) {
			return self::deepl( $segments, $from, $to, $key, $context );
		}
		$system = self::instructions( $from, $to, $context );
		$user   = wp_json_encode(
			array(
				'count' => count( $segments ),
				'items' => $segments,
			),
			JSON_UNESCAPED_UNICODE
		);
		switch ( $use['provider'] ) {
			case 'openai':
				$out = self::openai( $system, $user, $use['model'], $key );
				break;
			case 'google':
				$out = self::google( $system, $user, $use['model'], $key );
				break;
			default:
				$out = self::anthropic( $system, $user, $use['model'], $key );
		}
		if ( is_wp_error( $out ) ) {
			return $out;
		}
		$data = json_decode( (string) $out, true );
		if ( ! is_array( $data ) || ! isset( $data['t'] ) || ! is_array( $data['t'] ) || count( $data['t'] ) !== count( $segments ) ) {
			return new WP_Error( 'vacanampro_ai_reply', __( 'The translation service answered in an unexpected format. Please try again.', 'vacanam-language-switcher-pro' ) );
		}
		return array_map( 'strval', array_values( $data['t'] ) );
	}

	/**
	 * Instructions for AI services.
	 *
	 * @param array $from    Source language.
	 * @param array $to      Target language.
	 * @param array $context Context.
	 * @return string
	 */
	private static function instructions( $from, $to, $context ) {
		$lines   = array();
		$lines[] = sprintf( 'You translate website content from %1$s (%2$s) to %3$s (%4$s).', $from['name'], $from['locale'], $to['name'], $to['locale'] );
		$lines[] = 'You receive JSON {"count": N, "items": [...]}. Reply with JSON {"t": [...]} holding exactly N translations in the same order, one per item.';
		$lines[] = 'Translate only the human-readable text. Keep unchanged: HTML tags and their attributes, HTML entities, URLs, email addresses, shortcodes in square brackets, placeholders such as %%title%%, %s, {name} and {{name}}, numbers, code, and brand or product names.';
		$lines[] = 'Keep leading and trailing spaces. Never add explanations, quotes or notes. If an item is already in the target language or has nothing to translate, return it unchanged.';
		$lines[] = 'Write natural, fluent text for a website in the target language, with the correct punctuation and number formatting for it, as a native copywriter would.';
		if ( ! empty( $context['tone'] ) ) {
			$lines[] = 'Tone: ' . $context['tone'] . '.';
		}
		if ( ! empty( $context['variant'] ) ) {
			$lines[] = $context['variant'];
		}
		if ( ! empty( $context['about'] ) ) {
			$lines[] = 'About the website: ' . $context['about'];
		}
		if ( ! empty( $context['glossary'] ) ) {
			$lines[] = "Glossary (always follow it):\n" . $context['glossary'];
		}
		return implode( "\n", $lines );
	}

	/**
	 * JSON schema of the reply.
	 *
	 * @return array
	 */
	private static function schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				't' => array(
					'type'  => 'array',
					'items' => array( 'type' => 'string' ),
				),
			),
			'required'             => array( 't' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * POST JSON and decode the answer.
	 *
	 * @param string $url     Address.
	 * @param array  $headers Headers.
	 * @param array  $body    Body.
	 * @param string $name    Service name for messages.
	 * @return array|WP_Error
	 */
	private static function post_json( $url, $headers, $body, $name ) {
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 180,
				'headers' => array_merge( array( 'Content-Type' => 'application/json' ), $headers ),
				'body'    => wp_json_encode( $body, JSON_UNESCAPED_UNICODE ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( $code >= 400 || ! is_array( $data ) ) {
			$message = '';
			if ( is_array( $data ) ) {
				$message = isset( $data['error']['message'] ) ? $data['error']['message'] : ( isset( $data['message'] ) ? $data['message'] : '' );
			}
			/* translators: 1: service, 2: HTTP status, 3: message. */
			return new WP_Error( 'vacanampro_ai_http', sprintf( __( '%1$s error %2$d: %3$s', 'vacanam-language-switcher-pro' ), $name, $code, '' !== $message ? $message : __( 'no details', 'vacanam-language-switcher-pro' ) ) );
		}
		return $data;
	}

	/**
	 * Anthropic Messages API (JSON reply via structured outputs).
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @param string $model  Model.
	 * @param string $key    API key.
	 * @return string|WP_Error JSON text.
	 */
	private static function anthropic( $system, $user, $model, $key ) {
		$headers = array(
			'x-api-key'         => $key,
			'anthropic-version' => '2026-10-21',
		);
		$body    = array(
			'model'         => $model,
			'max_tokens'    => 16000,
			'system'        => $system,
			'messages'      => array(
				array(
					'role'    => 'user',
					'content' => $user,
				),
			),
			'output_config' => array(
				'format' => array(
					'type'   => 'json_schema',
					'schema' => self::schema(),
				),
			),
		);
		// If a safety check declines a request, let Anthropic answer with a fallback model instead.
		if ( 0 === strpos( $model, 'claude-opus-5' ) || 0 === strpos( $model, 'claude-sonnet-5-5' ) || 0 === strpos( $model, 'claude-fable-5' ) ) {
			$headers['anthropic-beta'] = 'server-side-fallback-2026-07-01';
			$body['fallbacks']         = 'default';
		}
		$data = self::post_json( 'https://api.anthropic.com/v1/messages', $headers, $body, 'Anthropic' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( isset( $data['stop_reason'] ) && 'refusal' === $data['stop_reason'] ) {
			return new WP_Error( 'vacanampro_ai_refusal', __( 'Anthropic declined to translate this content.', 'vacanam-language-switcher-pro' ) );
		}
		if ( isset( $data['stop_reason'] ) && 'max_tokens' === $data['stop_reason'] ) {
			return new WP_Error( 'vacanampro_ai_long', __( 'The text was too long for one request.', 'vacanam-language-switcher-pro' ) );
		}
		$text = '';
		foreach ( isset( $data['content'] ) ? (array) $data['content'] : array() as $block ) {
			if ( isset( $block['type'], $block['text'] ) && 'text' === $block['type'] ) {
				$text .= $block['text'];
			}
		}
		return $text;
	}

	/**
	 * OpenAI Chat Completions (JSON reply via a strict JSON schema).
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @param string $model  Model.
	 * @param string $key    API key.
	 * @return string|WP_Error
	 */
	private static function openai( $system, $user, $model, $key ) {
		$data = self::post_json(
			'https://api.openai.com/v1/chat/completions',
			array( 'Authorization' => 'Bearer ' . $key ),
			array(
				'model'           => $model,
				'messages'        => array(
					array(
						'role'    => 'system',
						'content' => $system,
					),
					array(
						'role'    => 'user',
						'content' => $user,
					),
				),
				'response_format' => array(
					'type'        => 'json_schema',
					'json_schema' => array(
						'name'   => 'translations',
						'strict' => true,
						'schema' => self::schema(),
					),
				),
			),
			'OpenAI'
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( ! empty( $data['choices'][0]['message']['refusal'] ) ) {
			return new WP_Error( 'vacanampro_ai_refusal', __( 'OpenAI declined to translate this content.', 'vacanam-language-switcher-pro' ) );
		}
		return isset( $data['choices'][0]['message']['content'] ) ? (string) $data['choices'][0]['message']['content'] : '';
	}

	/**
	 * Google Gemini (JSON reply via a response schema).
	 *
	 * @param string $system System prompt.
	 * @param string $user   User message.
	 * @param string $model  Model.
	 * @param string $key    API key.
	 * @return string|WP_Error
	 */
	private static function google( $system, $user, $model, $key ) {
		$data = self::post_json(
			'https://generativelanguage.googleapis.com/v1beta/models/' . rawurlencode( $model ) . ':generateContent',
			array( 'x-goog-api-key' => $key ),
			array(
				'systemInstruction' => array( 'parts' => array( array( 'text' => $system ) ) ),
				'contents'          => array(
					array(
						'role'  => 'user',
						'parts' => array( array( 'text' => $user ) ),
					),
				),
				'generationConfig'  => array(
					'responseMimeType' => 'application/json',
					'responseSchema'   => array(
						'type'       => 'OBJECT',
						'properties' => array(
							't' => array(
								'type'  => 'ARRAY',
								'items' => array( 'type' => 'STRING' ),
							),
						),
						'required'   => array( 't' ),
					),
				),
			),
			'Google'
		);
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		$text = '';
		foreach ( isset( $data['candidates'][0]['content']['parts'] ) ? (array) $data['candidates'][0]['content']['parts'] : array() as $part ) {
			$text .= isset( $part['text'] ) ? $part['text'] : '';
		}
		return $text;
	}

	/**
	 * DeepL language code.
	 *
	 * @param array $lang   Language.
	 * @param bool  $target Whether it is the target language (targets have regional variants).
	 * @return string
	 */
	public static function deepl_code( $lang, $target ) {
		$locale = strtolower( str_replace( '-', '_', (string) $lang['locale'] ) );
		$base   = strtoupper( substr( $locale, 0, 2 ) );
		if ( ! $target ) {
			return $base;
		}
		$map = array(
			'en_gb' => 'EN-GB',
			'pt_br' => 'PT-BR',
			'pt_pt' => 'PT-PT',
			'zh_tw' => 'ZH-HANT',
			'zh_hk' => 'ZH-HANT',
			'zh_cn' => 'ZH-HANS',
		);
		if ( isset( $map[ $locale ] ) ) {
			return $map[ $locale ];
		}
		$defaults = array(
			'EN' => 'EN-US',
			'PT' => 'PT-PT',
			'ZH' => 'ZH-HANS',
		);
		return isset( $defaults[ $base ] ) ? $defaults[ $base ] : $base;
	}

	/**
	 * DeepL (HTML aware; glossary terms that must stay are protected with a tag DeepL skips).
	 *
	 * @param string[] $segments Texts.
	 * @param array    $from     Source language.
	 * @param array    $to       Target language.
	 * @param string   $key      API key.
	 * @param array    $context  Context (keep => terms never translated).
	 * @return string[]|WP_Error
	 */
	private static function deepl( $segments, $from, $to, $key, $context ) {
		$keep = isset( $context['keep'] ) ? (array) $context['keep'] : array();
		$sent = array();
		foreach ( $segments as $text ) {
			foreach ( $keep as $term ) {
				$text = preg_replace( '/(?<![\p{L}\p{N}])' . preg_quote( $term, '/' ) . '(?![\p{L}\p{N}])/u', '<x-keep>$0</x-keep>', $text );
			}
			$sent[] = $text;
		}
		$host = ':fx' === substr( $key, -3 ) ? 'https://api-free.deepl.com' : 'https://api.deepl.com';
		$body = array(
			'text'         => $sent,
			'source_lang'  => self::deepl_code( $from, false ),
			'target_lang'  => self::deepl_code( $to, true ),
			'tag_handling' => 'html',
			'ignore_tags'  => array( 'x-keep', 'code', 'script', 'style' ),
		);
		if ( ! empty( $context['formality'] ) ) {
			$body['formality'] = $context['formality'];
		}
		$data = self::post_json( $host . '/v2/translate', array( 'Authorization' => 'DeepL-Auth-Key ' . $key ), $body, 'DeepL' );
		if ( is_wp_error( $data ) ) {
			return $data;
		}
		if ( empty( $data['translations'] ) || count( $data['translations'] ) !== count( $segments ) ) {
			return new WP_Error( 'vacanampro_ai_reply', __( 'DeepL answered in an unexpected format.', 'vacanam-language-switcher-pro' ) );
		}
		$out = array();
		foreach ( $data['translations'] as $item ) {
			$out[] = str_replace( array( '<x-keep>', '</x-keep>' ), '', (string) $item['text'] );
		}
		return $out;
	}

	/*
	 * Images.
	 */

	/**
	 * Service chosen for images ('' when none is ready).
	 *
	 * @return string openai, google or ''.
	 */
	public static function image_provider() {
		$provider = (string) VACANAMPRO_Settings::get( 'ai_image_provider' );
		if ( ! in_array( $provider, array( 'openai', 'google' ), true ) ) {
			$provider = '' !== self::key( 'openai' ) ? 'openai' : 'google';
		}
		return '' !== self::key( $provider ) ? $provider : '';
	}

	/**
	 * Draw an image again with its text in another language.
	 *
	 * @param string $path File.
	 * @param array  $from Source language.
	 * @param array  $to   Target language.
	 * @return string|WP_Error PNG bytes.
	 */
	public static function translate_image( $path, $from, $to ) {
		$provider = self::image_provider();
		if ( '' === $provider ) {
			return new WP_Error( 'vacanampro_ai_key', __( 'Add an OpenAI or Google API key in Languages > Pro to translate text in images.', 'vacanam-language-switcher-pro' ) );
		}
		$bytes = file_get_contents( $path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Local media file.
		if ( false === $bytes ) {
			return new WP_Error( 'vacanampro_ai_file', __( 'The image file could not be read.', 'vacanam-language-switcher-pro' ) );
		}
		$prompt = sprintf(
			'Recreate this exact image, identical in layout, size, colours, photos, logos and style, but replace every piece of written text with its %1$s (%2$s) translation from %3$s. Use typography that matches the original as closely as possible.%4$s Keep brand names and logos unchanged. Do not add, remove or move anything else.',
			$to['name'],
			$to['locale'],
			$from['name'],
			! empty( $to['rtl'] ) ? ' The new text is written right to left: set and align it correctly for a right-to-left language.' : ''
		);
		$mime = (string) wp_check_filetype( $path )['type'];
		if ( 'google' === $provider ) {
			$data = self::post_json(
				'https://generativelanguage.googleapis.com/v1beta/models/gemini-2.5-flash-image:generateContent',
				array( 'x-goog-api-key' => self::key( 'google' ) ),
				array(
					'contents' => array(
						array(
							'role'  => 'user',
							'parts' => array(
								array(
									'inline_data' => array(
										'mime_type' => '' !== $mime ? $mime : 'image/png',
										'data'      => base64_encode( $bytes ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- The API takes the image as base64.
									),
								),
								array( 'text' => $prompt ),
							),
						),
					),
				),
				'Google'
			);
			if ( is_wp_error( $data ) ) {
				return $data;
			}
			foreach ( isset( $data['candidates'][0]['content']['parts'] ) ? (array) $data['candidates'][0]['content']['parts'] : array() as $part ) {
				$inline = isset( $part['inlineData']['data'] ) ? $part['inlineData']['data'] : ( isset( $part['inline_data']['data'] ) ? $part['inline_data']['data'] : '' );
				if ( '' !== $inline ) {
					return (string) base64_decode( $inline ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The API returns the image as base64.
				}
			}
			return new WP_Error( 'vacanampro_ai_reply', __( 'Google did not return an image.', 'vacanam-language-switcher-pro' ) );
		}
		// OpenAI image edits: multipart form.
		$boundary = wp_generate_password( 24, false );
		$body     = '';
		foreach ( array(
			'model'  => 'gpt-image-1',
			'prompt' => $prompt,
			'size'   => 'auto',
		) as $name => $value ) {
			$body .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
		}
		$body    .= "--{$boundary}\r\nContent-Disposition: form-data; name=\"image\"; filename=\"" . basename( $path ) . "\"\r\nContent-Type: " . ( '' !== $mime ? $mime : 'image/png' ) . "\r\n\r\n" . $bytes . "\r\n--{$boundary}--\r\n";
		$response = wp_remote_post(
			'https://api.openai.com/v1/images/edits',
			array(
				'timeout' => 240,
				'headers' => array(
					'Authorization' => 'Bearer ' . self::key( 'openai' ),
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'body'    => $body,
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$data = json_decode( wp_remote_retrieve_body( $response ), true );
		if ( empty( $data['data'][0]['b64_json'] ) ) {
			$message = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'no image returned', 'vacanam-language-switcher-pro' );
			/* translators: %s: message. */
			return new WP_Error( 'vacanampro_ai_http', sprintf( __( 'OpenAI: %s', 'vacanam-language-switcher-pro' ), $message ) );
		}
		return (string) base64_decode( $data['data'][0]['b64_json'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- The API returns the image as base64.
	}
}
