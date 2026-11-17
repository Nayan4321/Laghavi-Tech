<?php
/**
 * Settings: one option holds every setting. The schema (tabs, sections, fields) drives the admin
 * screens, the defaults and the sanitizing.
 *
 * @package Transara
 */

defined( 'ABSPATH' ) || exit;

/**
 * Settings.
 */
class Transara_Settings {

	const OPTION = 'transara_settings';

	/**
	 * Cached values.
	 *
	 * @var array|null
	 */
	private static $values = null;

	/**
	 * Hook up.
	 */
	public static function init() {
		add_action( 'update_option_' . self::OPTION, array( __CLASS__, 'flush' ) );
	}

	/**
	 * Forget cached values.
	 */
	public static function flush() {
		self::$values = null;
	}

	/**
	 * The schema: tab => [ title, sections => [ id => [ title, description, fields => [ key => field ] ] ] ].
	 * Field: type (checkbox, text, number, select, lines), label, description, default, options, min, max, unit.
	 * Labels are translated when the screen is shown; defaults never are.
	 *
	 * @return array
	 */
	public static function schema() {
		static $cached = null;
		if ( null !== $cached && did_action( 'init' ) ) {
			return $cached;
		}
		$schema = array(
			'speed' => array(
				'title'    => __( 'Speed', 'transara' ),
				'sections' => array(
					'cache'   => array(
						'title'       => __( 'Page cache', 'transara' ),
						'description' => __( 'Pages are saved as ready HTML files and sent without running WordPress again. Pages change automatically when you edit content.', 'transara' ),
						'fields'      => array(
							'cache_enabled'        => array(
								'type'        => 'checkbox',
								'label'       => __( 'Turn on the page cache', 'transara' ),
								'default'     => 1,
							),
							'cache_mobile'         => array(
								'type'        => 'checkbox',
								'label'       => __( 'Separate cache for phones', 'transara' ),
								'description' => __( 'Turn on if your theme sends different HTML to phones (most responsive themes don\'t need this).', 'transara' ),
								'default'     => 0,
							),
							'cache_logged_in'      => array(
								'type'        => 'checkbox',
								'label'       => __( 'Cache for logged-in users', 'transara' ),
								'description' => __( 'Each logged-in session gets its own copies. Useful for membership sites; leave off if pages show live, personal data.', 'transara' ),
								'default'     => 0,
							),
							'cache_lifespan'       => array(
								'type'        => 'number',
								'label'       => __( 'Clear cached pages after', 'transara' ),
								'unit'        => __( 'hours (0 = never)', 'transara' ),
								'min'         => 0,
								'max'         => 8760,
								'default'     => 10,
							),
							'cache_reject_uri'     => array(
								'type'        => 'lines',
								'label'       => __( 'Never cache these pages', 'transara' ),
								'description' => __( 'One address per line, for example /members/ or /shop/(.*). Cart, checkout and account pages of WooCommerce are never cached.', 'transara' ),
								'default'     => array(),
							),
							'cache_reject_cookies' => array(
								'type'        => 'lines',
								'label'       => __( 'Never cache for visitors with these cookies', 'transara' ),
								'description' => __( 'One cookie name (or start of a name) per line.', 'transara' ),
								'default'     => array(),
							),
							'cache_reject_ua'      => array(
								'type'        => 'lines',
								'label'       => __( 'Never cache for these browsers', 'transara' ),
								'description' => __( 'One part of a user agent per line, for example Lighthouse.', 'transara' ),
								'default'     => array(),
							),
							'cache_query_strings'  => array(
								'type'        => 'lines',
								'label'       => __( 'Query strings that get their own cache', 'transara' ),
								'description' => __( 'Pages with any other query string are not cached. Tracking parameters (utm_source, fbclid, gclid…) are ignored and served from the normal cache.', 'transara' ),
								'default'     => array(),
							),
							'cache_always_purge'   => array(
								'type'        => 'lines',
								'label'       => __( 'Also clear these pages when any post changes', 'transara' ),
								'description' => __( 'Full addresses, one per line, for example a page that lists your latest posts.', 'transara' ),
								'default'     => array(),
							),
						),
					),
					'preload' => array(
						'title'       => __( 'Preload', 'transara' ),
						'description' => __( 'Fill the cache before visitors arrive, and start loading the next page while a visitor points at a link.', 'transara' ),
						'fields'      => array(
							'preload_enabled'  => array(
								'type'        => 'checkbox',
								'label'       => __( 'Preload the cache from your sitemap', 'transara' ),
								'description' => __( 'Uses WordPress\'s sitemap, or the one from Yoast SEO, Rank Math or SEOPress.', 'transara' ),
								'default'     => 1,
							),
							'preload_sitemaps' => array(
								'type'        => 'lines',
								'label'       => __( 'Other sitemaps', 'transara' ),
								'default'     => array(),
							),
							'preload_exclude'  => array(
								'type'        => 'lines',
								'label'       => __( 'Don\'t preload these pages', 'transara' ),
								'default'     => array(),
							),
							'link_preload'     => array(
								'type'        => 'select',
								'label'       => __( 'Load the next page early', 'transara' ),
								'options'     => array(
									'off'       => __( 'Off', 'transara' ),
									'prefetch'  => __( 'Download it when a visitor points at a link', 'transara' ),
									'prerender' => __( 'Fully prepare it when a visitor points at a link (fastest)', 'transara' ),
								),
								'default'     => 'prefetch',
							),
						),
					),
					'server'  => array(
						'title'       => __( 'Browser caching and compression', 'transara' ),
						'description' => __( 'Rules for Apache and LiteSpeed servers, written to .htaccess. Nginx users find the same rules in Tools.', 'transara' ),
						'fields'      => array(
							'browser_cache' => array(
								'type'    => 'checkbox',
								'label'   => __( 'Let browsers keep images, fonts, CSS and JavaScript', 'transara' ),
								'default' => 1,
							),
							'compression'   => array(
								'type'    => 'checkbox',
								'label'   => __( 'Compress pages and files (gzip / Brotli)', 'transara' ),
								'default' => 1,
							),
						),
					),
				),
			),
		);
		/**
		 * Settings schema (stages and add-ons add their tabs, sections and fields).
		 *
		 * @param array $schema Schema.
		 */
		$schema = (array) apply_filters( 'transara_schema', $schema );
		if ( did_action( 'init' ) ) {
			$cached = $schema;
		}
		return $schema;
	}

	/**
	 * Insert sections into a tab, before another section (or at the end).
	 *
	 * @param array  $schema   Schema.
	 * @param string $tab      Tab.
	 * @param array  $sections Sections to add.
	 * @param string $before   Section key to insert before.
	 * @return array
	 */
	public static function insert_sections( $schema, $tab, $sections, $before = '' ) {
		if ( ! isset( $schema[ $tab ] ) ) {
			return $schema;
		}
		$out = array();
		foreach ( $schema[ $tab ]['sections'] as $key => $section ) {
			if ( $key === $before ) {
				$out = array_merge( $out, $sections );
			}
			$out[ $key ] = $section;
		}
		if ( '' === $before || ! isset( $schema[ $tab ]['sections'][ $before ] ) ) {
			$out = array_merge( $out, $sections );
		}
		$schema[ $tab ]['sections'] = $out;
		return $schema;
	}

	/**
	 * Every field, by key.
	 *
	 * @return array
	 */
	public static function fields() {
		$out = array();
		foreach ( self::schema() as $tab ) {
			foreach ( $tab['sections'] as $section ) {
				foreach ( $section['fields'] as $key => $field ) {
					$out[ $key ] = $field;
				}
			}
		}
		return $out;
	}

	/**
	 * All values, with defaults.
	 *
	 * @return array
	 */
	public static function all() {
		if ( null === self::$values ) {
			$saved = get_option( self::OPTION, array() );
			$saved = is_array( $saved ) ? $saved : array();
			$vals  = array();
			foreach ( self::fields() as $key => $field ) {
				$vals[ $key ] = array_key_exists( $key, $saved ) ? $saved[ $key ] : ( isset( $field['default'] ) ? $field['default'] : '' );
			}
			// Keys stored by add-ons that are not in the schema right now.
			self::$values = array_merge( $saved, $vals );
		}
		return self::$values;
	}

	/**
	 * One value.
	 *
	 * @param string $key Key.
	 * @return mixed
	 */
	public static function get( $key ) {
		$all = self::all();
		return isset( $all[ $key ] ) ? $all[ $key ] : null;
	}

	/**
	 * Whether a checkbox is on.
	 *
	 * @param string $key Key.
	 * @return bool
	 */
	public static function on( $key ) {
		return ! empty( self::get( $key ) );
	}

	/**
	 * A "lines" setting as an array of non-empty lines.
	 *
	 * @param string $key Key.
	 * @return string[]
	 */
	public static function lines( $key ) {
		$v = self::get( $key );
		return is_array( $v ) ? array_values( array_filter( array_map( 'trim', $v ), 'strlen' ) ) : array();
	}

	/**
	 * Clean one posted value.
	 *
	 * @param array $field Field.
	 * @param mixed $raw   Raw value (unslashed).
	 * @return mixed
	 */
	public static function clean( $field, $raw ) {
		switch ( $field['type'] ) {
			case 'checkbox':
				return empty( $raw ) ? 0 : 1;
			case 'number':
				$n = is_numeric( $raw ) ? 0 + $raw : ( isset( $field['default'] ) ? $field['default'] : 0 );
				if ( isset( $field['min'] ) ) {
					$n = max( $field['min'], $n );
				}
				if ( isset( $field['max'] ) ) {
					$n = min( $field['max'], $n );
				}
				return $n;
			case 'select':
				$raw = is_string( $raw ) ? $raw : '';
				return isset( $field['options'][ $raw ] ) ? $raw : $field['default'];
			case 'lines':
				$lines = preg_split( '/\r\n|\r|\n/', is_string( $raw ) ? $raw : '' );
				return array_values( array_filter( array_map( static function ( $l ) {
					return trim( sanitize_text_field( $l ) );
				}, $lines ), 'strlen' ) );
			case 'secret':
			case 'text':
			default:
				return is_string( $raw ) ? sanitize_text_field( $raw ) : '';
		}
	}

	/**
	 * Save some values (keeps the others).
	 *
	 * @param array $values key => value (already clean).
	 */
	public static function update( $values ) {
		$saved = get_option( self::OPTION, array() );
		$saved = is_array( $saved ) ? $saved : array();
		update_option( self::OPTION, array_merge( $saved, $values ) );
		self::flush();
		/**
		 * Settings saved.
		 *
		 * @param array $values Changed values.
		 */
		do_action( 'transara_settings_saved', $values );
	}
}
