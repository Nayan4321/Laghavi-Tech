<?php
/**
 * Developer tools: REST API, JavaScript API and events, and custom language providers.
 *
 * @package VacanamLanguageSwitcherPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * API.
 */
class VACANAMPRO_API {

	const NS = 'vacanam/v1';

	/**
	 * Language providers: id => array( detect, url, priority ).
	 *
	 * @var array
	 */
	private static $providers = array();

	/**
	 * Providers run while the language is found (before plugins_loaded, so they work for every visit).
	 */
	public static function init_routing() {
		add_filter( 'vacanam_detect_language', array( __CLASS__, 'detect' ), 20, 2 );
		add_filter( 'vacanam_url_for_language', array( __CLASS__, 'url_for' ), 20, 4 );
	}

	/**
	 * Hook everything up (licensed).
	 */
	public static function init() {
		add_filter( 'vacanampro_setting_defaults', array( __CLASS__, 'defaults' ) );
		add_action( 'vacanampro_settings_sections', array( __CLASS__, 'section' ), 90 );
		add_filter( 'vacanampro_save_settings', array( __CLASS__, 'save_settings' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'script' ), 20 );
	}

	/**
	 * Defaults.
	 *
	 * @param array $defaults Defaults.
	 * @return array
	 */
	public static function defaults( $defaults ) {
		$defaults['js_api'] = 'no';
		return $defaults;
	}

	/*
	 * Language providers.
	 */

	/**
	 * Register a way to find the language of a visit.
	 *
	 * @param string $id   Unique name.
	 * @param array  $args detect: callable( string $mode ) returning a language slug or '';
	 *                     url: optional callable( string $url, string $slug ) returning the address in a language or null;
	 *                     priority: lower runs first (default 10).
	 */
	public static function register_provider( $id, $args ) {
		$args = wp_parse_args(
			$args,
			array(
				'detect'   => null,
				'url'      => null,
				'priority' => 10,
			)
		);
		if ( ! is_callable( $args['detect'] ) ) {
			return;
		}
		self::$providers[ sanitize_key( $id ) ] = $args;
		uasort(
			self::$providers,
			static function ( $a, $b ) {
				return (int) $a['priority'] - (int) $b['priority'];
			}
		);
	}

	/**
	 * Registered providers.
	 *
	 * @return array
	 */
	public static function providers() {
		return self::$providers;
	}

	/**
	 * Ask the providers when the address shows no language.
	 *
	 * @param string $lang Language found so far.
	 * @param string $mode Address mode.
	 * @return string
	 */
	public static function detect( $lang, $mode ) {
		if ( '' !== $lang || ! self::$providers ) {
			return $lang;
		}
		foreach ( self::$providers as $provider ) {
			$found = (string) call_user_func( $provider['detect'], $mode );
			if ( '' !== $found && Vacanam_Languages::exists( $found ) ) {
				return $found;
			}
		}
		return $lang;
	}

	/**
	 * Addresses from providers that build their own.
	 *
	 * @param string $out  Address.
	 * @param string $url  Address as given.
	 * @param string $slug Language.
	 * @param string $mode Address mode.
	 * @return string
	 */
	public static function url_for( $out, $url, $slug, $mode ) {
		unset( $mode );
		foreach ( self::$providers as $provider ) {
			if ( is_callable( $provider['url'] ) ) {
				$made = call_user_func( $provider['url'], $url, $slug );
				if ( is_string( $made ) && '' !== $made ) {
					return $made;
				}
			}
		}
		return $out;
	}

	/*
	 * REST API.
	 */

	/**
	 * Routes.
	 */
	public static function routes() {
		$id = array(
			'id' => array(
				'validate_callback' => static function ( $v ) {
					return is_numeric( $v );
				},
			),
		);
		register_rest_route(
			self::NS,
			'/languages',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_languages' ),
				'permission_callback' => '__return_true',
			)
		);
		register_rest_route(
			self::NS,
			'/posts/(?P<id>\d+)/translations',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_post_translations' ),
					'permission_callback' => array( __CLASS__, 'can_read_post' ),
					'args'                => $id,
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_create_translation' ),
					'permission_callback' => array( __CLASS__, 'can_edit_post' ),
					'args'                => array_merge(
						$id,
						array(
							'lang'      => array(
								'required'          => true,
								'sanitize_callback' => 'sanitize_key',
							),
							'translate' => array(
								'type'    => 'boolean',
								'default' => false,
							),
						)
					),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/terms/(?P<id>\d+)/translations',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_term_translations' ),
				'permission_callback' => '__return_true',
				'args'                => $id,
			)
		);
		register_rest_route(
			self::NS,
			'/strings',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'rest_strings' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'rest_save_strings' ),
					'permission_callback' => array( __CLASS__, 'can_manage' ),
				),
			)
		);
		register_rest_route(
			self::NS,
			'/url',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'rest_url' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'url'  => array(
						'required'          => true,
						'sanitize_callback' => 'esc_url_raw',
					),
					'lang' => array(
						'required'          => true,
						'sanitize_callback' => 'sanitize_key',
					),
				),
			)
		);
		// Language and translations on the posts and terms of the core REST API.
		foreach ( Vacanam_Model::post_types() as $type ) {
			register_rest_field(
				$type,
				'vacanam',
				array(
					'get_callback' => static function ( $post ) {
						return array(
							'lang'         => Vacanam_Model::post_lang( (int) $post['id'] ),
							'translations' => array_map( 'intval', Vacanam_Model::post_translations( (int) $post['id'] ) ),
						);
					},
					'schema'       => array(
						'description' => __( 'Language and translations (Vacanam).', 'vacanam-language-switcher-pro' ),
						'type'        => 'object',
						'context'     => array( 'view', 'edit' ),
						'readonly'    => true,
					),
				)
			);
		}
		foreach ( Vacanam_Model::taxonomies() as $tax ) {
			register_rest_field(
				$tax,
				'vacanam',
				array(
					'get_callback' => static function ( $term ) {
						return array(
							'lang'         => Vacanam_Model::term_lang( (int) $term['id'] ),
							'translations' => array_map( 'intval', Vacanam_Model::term_translations( (int) $term['id'] ) ),
						);
					},
					'schema'       => array(
						'type'     => 'object',
						'context'  => array( 'view', 'edit' ),
						'readonly' => true,
					),
				)
			);
		}
	}

	/**
	 * May the visitor see this post?
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_read_post( $request ) {
		$id = (int) $request['id'];
		return 'publish' === get_post_status( $id ) && is_post_publicly_viewable( $id ) || current_user_can( 'edit_post', $id );
	}

	/**
	 * May the user edit this post?
	 *
	 * @param WP_REST_Request $request Request.
	 * @return bool
	 */
	public static function can_edit_post( $request ) {
		return current_user_can( 'edit_post', (int) $request['id'] );
	}

	/**
	 * May the user manage languages?
	 *
	 * @return bool
	 */
	public static function can_manage() {
		return current_user_can( VACANAMPRO_Settings::capability() );
	}

	/**
	 * GET /languages.
	 *
	 * @return WP_REST_Response
	 */
	public static function rest_languages() {
		$out = array();
		foreach ( Vacanam_Languages::all() as $slug => $lang ) {
			if ( ! $lang['active'] && ! current_user_can( 'edit_posts' ) ) {
				continue;
			}
			$out[] = array(
				'slug'     => $slug,
				'name'     => $lang['name'],
				'locale'   => $lang['locale'],
				'hreflang' => Vacanam_Languages::hreflang( $lang ),
				'rtl'      => (bool) $lang['rtl'],
				'default'  => Vacanam_Languages::default_slug() === $slug,
				'flag'     => Vacanam_Languages::lang_flag_url( $lang ),
				'home'     => Vacanam_Router::home_for( $slug ),
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * GET /posts/{id}/translations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_post_translations( $request ) {
		$id = (int) $request['id'];
		if ( ! get_post( $id ) ) {
			return new WP_Error( 'vacanam_not_found', __( 'Not found.', 'vacanam-language-switcher-pro' ), array( 'status' => 404 ) );
		}
		$out = array();
		foreach ( Vacanam_Model::post_translations( $id ) as $slug => $tr ) {
			if ( 'publish' !== get_post_status( $tr ) && ! current_user_can( 'edit_post', $tr ) ) {
				continue;
			}
			$status       = VACANAMPRO_Status::get( $tr );
			$out[ $slug ] = array(
				'id'     => (int) $tr,
				'title'  => get_the_title( $tr ),
				'link'   => get_permalink( $tr ),
				'status' => get_post_status( $tr ),
				'done'   => $status['status'],
			);
		}
		return rest_ensure_response(
			array(
				'id'           => $id,
				'lang'         => Vacanam_Model::post_lang( $id ),
				'translations' => $out,
			)
		);
	}

	/**
	 * POST /posts/{id}/translations: copy into a language (and translate with AI if asked).
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_create_translation( $request ) {
		$id   = (int) $request['id'];
		$lang = (string) $request['lang'];
		if ( ! get_post( $id ) || ! Vacanam_Languages::exists( $lang ) ) {
			return new WP_Error( 'vacanam_invalid', __( 'Unknown post or language.', 'vacanam-language-switcher-pro' ), array( 'status' => 400 ) );
		}
		$tr = Vacanam_Model::post_translation( $id, $lang );
		if ( ! $tr ) {
			$tr = Vacanam_Duplicator::duplicate_post( $id, $lang );
		}
		if ( is_wp_error( $tr ) || ! $tr ) {
			return is_wp_error( $tr ) ? $tr : new WP_Error( 'vacanam_copy', __( 'The copy could not be made.', 'vacanam-language-switcher-pro' ), array( 'status' => 500 ) );
		}
		$translated = null;
		if ( $request['translate'] ) {
			if ( ! VACANAMPRO_AI::ready() ) {
				return new WP_Error( 'vacanam_ai', __( 'Add a translation service key in Languages > Pro first.', 'vacanam-language-switcher-pro' ), array( 'status' => 400 ) );
			}
			$steps = 0;
			do {
				$translated = VACANAMPRO_Translator::translate_post( (int) $tr, 3 );
				++$steps;
			} while ( ! is_wp_error( $translated ) && 'more' === $translated['status'] && $steps < 20 );
			if ( is_wp_error( $translated ) ) {
				return $translated;
			}
		}
		return rest_ensure_response(
			array(
				'id'         => (int) $tr,
				'link'       => get_permalink( $tr ),
				'edit'       => get_edit_post_link( $tr, 'raw' ),
				'translated' => $translated ? 'done' === $translated['status'] : false,
			)
		);
	}

	/**
	 * GET /terms/{id}/translations.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_term_translations( $request ) {
		$term = get_term( (int) $request['id'] );
		if ( ! $term instanceof WP_Term ) {
			return new WP_Error( 'vacanam_not_found', __( 'Not found.', 'vacanam-language-switcher-pro' ), array( 'status' => 404 ) );
		}
		$out = array();
		foreach ( Vacanam_Model::term_translations( $term->term_id ) as $slug => $tr ) {
			$t = get_term( (int) $tr );
			if ( $t instanceof WP_Term ) {
				$link         = get_term_link( $t );
				$out[ $slug ] = array(
					'id'   => (int) $tr,
					'name' => $t->name,
					'link' => is_wp_error( $link ) ? '' : $link,
				);
			}
		}
		return rest_ensure_response(
			array(
				'id'           => $term->term_id,
				'lang'         => Vacanam_Model::term_lang( $term->term_id ),
				'translations' => $out,
			)
		);
	}

	/**
	 * GET /strings?lang=&group=&status=&search=&page=.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response
	 */
	public static function rest_strings( $request ) {
		VACANAMPRO_Strings::sync_free();
		$result = VACANAMPRO_Strings::query(
			array(
				'lang'   => sanitize_key( (string) $request['lang'] ),
				'group'  => sanitize_text_field( (string) $request['group'] ),
				'status' => sanitize_key( (string) $request['status'] ),
				's'      => sanitize_text_field( (string) $request['search'] ),
				'page'   => max( 1, (int) $request['page'] ),
			)
		);
		$out    = array();
		foreach ( $result['rows'] as $row ) {
			$out[] = array(
				'id'          => $row['hash'],
				'kind'        => $row['kind'],
				'group'       => $row['domain'],
				'context'     => $row['context'],
				'name'        => $row['name'],
				'original'    => $row['original'],
				'plural'      => $row['plural'],
				'translation' => (string) $row['translation'],
				'tr_plural'   => (string) $row['tr_plural'],
			);
		}
		$response = rest_ensure_response( $out );
		$response->header( 'X-WP-Total', (string) $result['total'] );
		return $response;
	}

	/**
	 * POST /strings { lang, items: { id: translation } }.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_save_strings( $request ) {
		$lang  = sanitize_key( (string) $request['lang'] );
		$items = (array) $request['items'];
		if ( ! Vacanam_Languages::exists( $lang ) ) {
			return new WP_Error( 'vacanam_invalid', __( 'Unknown language.', 'vacanam-language-switcher-pro' ), array( 'status' => 400 ) );
		}
		$saved = 0;
		foreach ( $items as $hash => $text ) {
			$hash = preg_replace( '/[^a-f0-9]/', '', (string) $hash );
			if ( 32 === strlen( $hash ) && is_scalar( $text ) ) {
				VACANAMPRO_Strings::set( $hash, $lang, wp_kses_post( (string) $text ) );
				++$saved;
			}
		}
		return rest_ensure_response( array( 'saved' => $saved ) );
	}

	/**
	 * GET /url?url=&lang=: an address of this site in another language.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function rest_url( $request ) {
		$lang = (string) $request['lang'];
		$url  = (string) $request['url'];
		if ( ! Vacanam_Languages::exists( $lang ) ) {
			return new WP_Error( 'vacanam_invalid', __( 'Unknown language.', 'vacanam-language-switcher-pro' ), array( 'status' => 400 ) );
		}
		$post = url_to_postid( $url );
		if ( $post ) {
			$tr = Vacanam_Model::post_translation( $post, $lang );
			if ( $tr && 'publish' === get_post_status( $tr ) ) {
				return rest_ensure_response(
					array(
						'url'        => get_permalink( $tr ),
						'translated' => true,
					)
				);
			}
		}
		return rest_ensure_response(
			array(
				'url'        => Vacanam_Router::url_for( $url, $lang ),
				'translated' => (bool) $post && Vacanam_Model::post_lang( $post ) === $lang,
			)
		);
	}

	/*
	 * JavaScript API.
	 */

	/**
	 * window.Vacanam and the vacanam:* events.
	 */
	public static function script() {
		if ( ! VACANAMPRO_Settings::on( 'js_api' ) ) {
			return;
		}
		$langs = array();
		foreach ( Vacanam_Switcher::items() as $slug => $item ) {
			$langs[ $slug ] = array(
				'name'       => $item['name'],
				'url'        => $item['url'],
				'hreflang'   => $item['hreflang'],
				'rtl'        => (bool) $item['rtl'],
				'translated' => empty( $item['missing'] ),
			);
		}
		wp_enqueue_script( 'vacanampro-api', VACANAMPRO_URL . 'assets/js/vacanam-api.js', array(), VACANAMPRO_VERSION, false );
		wp_add_inline_script(
			'vacanampro-api',
			'window.vacanamData = ' . wp_json_encode(
				array(
					'current'   => vacanam_current_language(),
					'default'   => Vacanam_Languages::default_slug(),
					'languages' => $langs,
					'rest'      => esc_url_raw( rest_url( self::NS ) ),
				)
			) . ';',
			'before'
		);
	}

	/*
	 * Settings.
	 */

	/**
	 * Developer section.
	 */
	public static function section() {
		$rest = rest_url( self::NS );
		echo '<div class="vacanam-card"><h2>' . esc_html__( 'Developers', 'vacanam-language-switcher-pro' ) . '</h2><table class="form-table" role="presentation">';
		echo '<tr><th scope="row">' . esc_html__( 'JavaScript API', 'vacanam-language-switcher-pro' ) . '</th><td><label><input type="checkbox" name="vacanampro_js_api" value="1"' . checked( VACANAMPRO_Settings::on( 'js_api' ), true, false ) . ' /> ' . esc_html__( 'Load window.Vacanam and the vacanam:* events on the website (1 KB)', 'vacanam-language-switcher-pro' ) . '</label>';
		echo '<p class="description"><code>Vacanam.current</code>, <code>Vacanam.languages</code>, <code>Vacanam.urlFor( \'ar\' )</code>, <code>Vacanam.switchTo( \'ar\' )</code>, <code>Vacanam.on( \'beforeSwitch\', fn )</code>. ' . esc_html__( 'Events on document:', 'vacanam-language-switcher-pro' ) . ' <code>vacanam:ready</code>, <code>vacanam:beforeSwitch</code> ' . esc_html__( '(cancelable)', 'vacanam-language-switcher-pro' ) . ', <code>vacanam:languageChanged</code>.</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'REST API', 'vacanam-language-switcher-pro' ) . '</th><td><p class="description"><code>GET ' . esc_html( $rest ) . '/languages</code><br /><code>GET|POST /posts/{id}/translations</code> ' . esc_html__( '(POST lang=ar&translate=1 copies the post into a language and translates it)', 'vacanam-language-switcher-pro' ) . '<br /><code>GET /terms/{id}/translations</code><br /><code>GET|POST /strings</code> ' . esc_html__( '(for administrators)', 'vacanam-language-switcher-pro' ) . '<br /><code>GET /url?url=…&amp;lang=ar</code><br />' . esc_html__( 'Posts and terms of the WordPress REST API also carry a "vacanam" field with their language and translations.', 'vacanam-language-switcher-pro' ) . '</p></td></tr>';
		echo '<tr><th scope="row">' . esc_html__( 'PHP', 'vacanam-language-switcher-pro' ) . '</th><td><p class="description"><code>vacanampro_register_language_provider( \'my-provider\', array( \'detect\' =&gt; fn, \'url\' =&gt; fn, \'priority\' =&gt; 10 ) )</code> ' . esc_html__( 'finds the language of a visit your own way (a cookie, a header, a user setting) when the address shows none.', 'vacanam-language-switcher-pro' ) . '<br />' . esc_html__( 'Hooks:', 'vacanam-language-switcher-pro' ) . ' <code>vacanampro_post_translated</code>, <code>vacanampro_post_parts</code>, <code>vacanampro_seo_options</code>, <code>vacanam_detect_language</code>, <code>vacanam_url_for_language</code>, <code>vacanam_language_changed</code>, <code>vacanam_switcher_items</code>, <code>vacanam_strings</code>. ' . esc_html__( 'WPML and Polylang functions (icl_object_id, wpml_object_id, pll_current_language…) work too.', 'vacanam-language-switcher-pro' ) . '</p></td></tr>';
		echo '</table></div>';
	}

	/**
	 * Save.
	 *
	 * @param array $values Values.
	 * @return array
	 */
	public static function save_settings( $values ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Checked in VACANAMPRO_Settings::save().
		$values['js_api'] = empty( $_POST['vacanampro_js_api'] ) ? 'no' : 'yes';
		return $values;
	}
}

/**
 * Register a way to find the language of a visit (call it when your plugin loads).
 *
 * @param string $id   Unique name.
 * @param array  $args detect, url, priority (see VACANAMPRO_API::register_provider()).
 */
function vacanampro_register_language_provider( $id, $args ) {
	VACANAMPRO_API::register_provider( $id, $args );
}
