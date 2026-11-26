<?php
/**
 * Front end: shortcode, styles and script (loaded only on pages with a feed), "Load more" endpoint.
 *
 * @package NyasahSocialFeed
 */

defined( 'ABSPATH' ) || exit;

/**
 * Front.
 */
class NYASAH_Front {

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_shortcode( 'nyasah-feed', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'nyasah_feed', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'register' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'register' ) );
		add_action( 'rest_api_init', array( __CLASS__, 'routes' ) );
	}

	/**
	 * Register the style and script.
	 */
	public static function register() {
		wp_register_style( 'nyasah', NYASAH_URL . 'assets/css/front.css', array(), nyasah_asset_version( 'assets/css/front.css' ) );
		wp_register_script( 'nyasah', NYASAH_URL . 'assets/js/front.js', array(), nyasah_asset_version( 'assets/js/front.js' ), true );
		wp_localize_script(
			'nyasah',
			'nyasahFront',
			array(
				'rest'  => esc_url_raw( rest_url( 'nyasah/v1/feed/' ) ),
				'close' => __( 'Close', 'nyasah-social-feed' ),
				'prev'  => __( 'Previous', 'nyasah-social-feed' ),
				'next'  => __( 'Next', 'nyasah-social-feed' ),
				'error' => __( 'Could not load more posts.', 'nyasah-social-feed' ),
			)
		);
	}

	/**
	 * Load them on the page being shown.
	 */
	public static function assets() {
		if ( ! wp_style_is( 'nyasah', 'registered' ) ) {
			self::register();
		}
		wp_enqueue_style( 'nyasah' );
		wp_enqueue_script( 'nyasah' );
	}

	/**
	 * [nyasah-feed id="12"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts( array( 'id' => 0 ), $atts, 'nyasah-feed' );
		return self::by_id( (int) $atts['id'] );
	}

	/**
	 * A saved feed.
	 *
	 * @param int $id Feed ID.
	 * @return string
	 */
	public static function by_id( $id ) {
		$s = NYASAH_Feeds::get( $id );
		if ( ! $s ) {
			return current_user_can( 'manage_options' ) ? '<div class="nyasah-error">' . esc_html__( 'Nyasah Social Feed: this feed does not exist.', 'nyasah-social-feed' ) . '</div>' : '';
		}
		return NYASAH_Render::feed( $s );
	}

	/**
	 * REST route for "Load more".
	 */
	public static function routes() {
		register_rest_route(
			'nyasah/v1',
			'/feed/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'more' ),
				// Public on purpose: it returns the same public posts the page already shows.
				'permission_callback' => '__return_true',
				'args'                => array(
					'id'     => array( 'sanitize_callback' => 'absint' ),
					'offset' => array(
						'sanitize_callback' => 'absint',
						'default'           => 0,
					),
				),
			)
		);
	}

	/**
	 * Next posts of a feed.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public static function more( $request ) {
		$s = NYASAH_Feeds::get( (int) $request['id'] );
		if ( ! $s ) {
			return new WP_Error( 'nyasah_feed', __( 'This feed does not exist.', 'nyasah-social-feed' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( NYASAH_Render::page( $s, min( 500, (int) $request['offset'] ) ) );
	}
}
