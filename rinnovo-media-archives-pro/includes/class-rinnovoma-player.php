<?php
/**
 * Protected players: [media_video] and [media_audio] (and Presto Player videos) for subscribers only.
 * Players get a short-lived link through the site, which forwards to the host's signed, expiring URL, so
 * the page source never holds the real file address. Right-click, dragging and download buttons are off,
 * playback only works from your own pages, a moving watermark shows the subscriber's email and IP over
 * the player, and simultaneous streams per account are limited.
 *
 * @package RinnovoMedia
 */

defined( 'ABSPATH' ) || exit;

/**
 * Players.
 */
class RINNOVOMA_Player {

	const TD = 'rinnovo-media-archives-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_shortcode( 'media_video', array( __CLASS__, 'video' ) );
		add_shortcode( 'media_audio', array( __CLASS__, 'audio' ) );
		add_action( 'init', array( __CLASS__, 'serve' ), 20 );
		add_action( 'wp_ajax_rinnovoma_stream', array( __CLASS__, 'stream_heartbeat' ) );
	}

	/**
	 * Sign data with the site's secret.
	 *
	 * @param string $data Data.
	 * @return string
	 */
	public static function sign( $data ) {
		return substr( hash_hmac( 'sha256', $data, (string) RINNOVOMA_Plans::get( 'jwt_secret' ) ), 0, 32 );
	}

	/**
	 * A short-lived link through the site for a media item.
	 *
	 * @param string $provider Provider.
	 * @param string $id       ID / path / key / attachment.
	 * @param int    $user_id  Subscriber.
	 * @param int    $ttl      Seconds.
	 * @param string $via      page or feed.
	 * @return string
	 */
	public static function media_link( $provider, $id, $user_id, $ttl = 300, $via = 'page' ) {
		$data = rtrim( strtr( base64_encode( wp_json_encode( array( $provider, (string) $id, (int) $user_id, time() + $ttl, $via ) ) ), '+/', '-_' ), '=' ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Link payload.
		return add_query_arg(
			array(
				'rinnovoma_media' => $data,
				's'               => self::sign( $data ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Whether a request comes from this site's pages (players load media with the page as referrer).
	 *
	 * @return bool
	 */
	public static function from_own_site() {
		if ( ! RINNOVOMA_Plans::get( 'referer_lock' ) ) {
			return true;
		}
		$ref = isset( $_SERVER['HTTP_REFERER'] ) ? (string) wp_parse_url( esc_url_raw( wp_unslash( $_SERVER['HTTP_REFERER'] ) ), PHP_URL_HOST ) : '';
		// Media elements may send no referrer at all; a referrer from another site is refused.
		return '' === $ref || strtolower( $ref ) === strtolower( (string) wp_parse_url( home_url(), PHP_URL_HOST ) );
	}

	/**
	 * Follow a short-lived link: check it, the subscription and the referrer, then send the media.
	 */
	public static function serve() {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Signed link.
		if ( empty( $_GET['rinnovoma_media'] ) || empty( $_GET['s'] ) ) {
			return;
		}
		$data = sanitize_text_field( wp_unslash( $_GET['rinnovoma_media'] ) );
		$sig  = sanitize_key( wp_unslash( $_GET['s'] ) );
		// phpcs:enable
		$json = json_decode( (string) base64_decode( strtr( $data, '-_', '+/' ) ), true ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Link payload.
		if ( ! hash_equals( self::sign( $data ), $sig ) || ! is_array( $json ) || count( $json ) < 5 || (int) $json[3] < time() ) {
			status_header( 403 );
			exit( esc_html__( 'This link has expired.', 'rinnovo-media-archives-pro' ) );
		}
		list( $provider, $id, $user_id, , $via ) = $json;
		if ( ! RINNOVOMA_Access::has( (int) $user_id ) || ( 'page' === $via && ( ! self::from_own_site() || get_current_user_id() !== (int) $user_id ) ) ) {
			status_header( 403 );
			exit( esc_html__( 'Your subscription does not include this, or it has ended.', 'rinnovo-media-archives-pro' ) );
		}
		nocache_headers();
		header( 'X-Robots-Tag: noindex' );
		header( 'Referrer-Policy: no-referrer' );
		if ( 'file' === $provider ) {
			RINNOVOMA_Gallery::stream_attachment( absint( $id ) );
		}
		wp_redirect( RINNOVOMA_Signer::url( $provider, $id ), 302 ); // phpcs:ignore WordPress.Security.SafeRedirect.wp_redirect_wp_redirect -- The media host.
		exit;
	}

	/**
	 * Watermark text for the current subscriber.
	 *
	 * @return string
	 */
	public static function watermark_text() {
		$user = wp_get_current_user();
		return strtr(
			(string) RINNOVOMA_Plans::get( 'watermark_text' ),
			array(
				'{email}' => $user->user_email,
				'{name}'  => $user->display_name,
				'{ip}'    => RINNOVOMA_Security::ip(),
				'{date}'  => wp_date( 'Y-m-d' ),
				'{id}'    => (string) $user->ID,
			)
		);
	}

	/**
	 * Box shown instead of the player.
	 *
	 * @param string $plan Plan needed.
	 * @param string $kind video, audio, art.
	 * @return string
	 */
	public static function locked( $plan, $kind = 'video' ) {
		$p   = RINNOVOMA_Plans::get_plan( $plan );
		$pid = $p && $p['products'] ? $p['products'][0] : 0;
		if ( ! $pid ) {
			$all = RINNOVOMA_Plans::all();
			$pid = $all && current( $all )['products'] ? current( $all )['products'][0] : 0;
		}
		$product = $pid ? wc_get_product( $pid ) : null;
		$url     = $product ? ( $product->is_type( 'variation' ) ? get_permalink( $product->get_parent_id() ) : $product->get_permalink() ) : wc_get_page_permalink( 'shop' );
		$titles  = array(
			'video' => __( 'This video is for subscribers', self::TD ),
			'audio' => __( 'This episode is for subscribers', self::TD ),
			'art'   => __( 'This collection is for subscribers', self::TD ),
		);
		$html    = '<div class="rinnovoma-locked"><p class="rinnovoma-locked__title">' . esc_html( isset( $titles[ $kind ] ) ? $titles[ $kind ] : $titles['video'] ) . '</p>';
		$html   .= '<p><a class="button alt" href="' . esc_url( $url ) . '">' . esc_html__( 'Subscribe to unlock', self::TD ) . '</a></p>';
		if ( ! is_user_logged_in() ) {
			$html .= '<p class="rinnovoma-locked__login">' . esc_html__( 'Already subscribed?', self::TD ) . ' <a href="' . esc_url( wp_login_url( get_permalink() ) ) . '">' . esc_html__( 'Log in', self::TD ) . '</a></p>';
		}
		return $html . '</div>';
	}

	/**
	 * The protecting wrapper (watermark, no right-click, stream limit).
	 *
	 * @param string $inner Player HTML.
	 * @param string $kind  video, audio, art.
	 * @return string
	 */
	public static function wrap( $inner, $kind ) {
		wp_enqueue_script( 'rinnovoma' );
		wp_localize_script(
			'rinnovoma',
			'rinnovomaPlayer',
			array(
				'ajax'    => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'rinnovoma_stream' ),
				'message' => __( 'Your account is already playing on other devices. Stop one of them to watch here.', self::TD ),
			)
		);
		$attrs = ' data-rinnovoma-kind="' . esc_attr( $kind ) . '"';
		if ( RINNOVOMA_Plans::get( 'watermark' ) ) {
			$attrs .= ' data-rinnovoma-watermark="' . esc_attr( self::watermark_text() ) . '"';
		}
		if ( 'art' !== $kind ) {
			$attrs .= ' data-rinnovoma-stream="' . esc_attr( wp_generate_uuid4() ) . '"';
		}
		$class = 'rinnovoma-player rinnovoma-player--' . $kind . ( RINNOVOMA_Plans::get( 'protect' ) ? ' rinnovoma-protect' : '' );
		return '<div class="' . esc_attr( $class ) . '"' . $attrs . '>' . $inner . '</div>';
	}

	/**
	 * Attributes shared by the shortcodes.
	 *
	 * @param array  $atts Attributes.
	 * @param string $tag  Shortcode.
	 * @return array
	 */
	private static function atts( $atts, $tag ) {
		$atts             = shortcode_atts(
			array(
				'provider' => 'bunny',
				'id'       => '',
				'plan'     => 'any',
				'title'    => '',
				'poster'   => '',
				'ratio'    => '16:9',
			),
			$atts,
			$tag
		);
		$atts['provider'] = sanitize_key( $atts['provider'] );
		$atts['plan']     = sanitize_key( $atts['plan'] );
		return $atts;
	}

	/**
	 * [media_video provider="bunny|cloudflare|vimeo|bunnycdn|cloudfront|s3|file|presto" id="…" plan="pro"]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function video( $atts ) {
		$a = self::atts( $atts, 'media_video' );
		if ( ! RINNOVOMA_Access::has( get_current_user_id(), $a['plan'] ) ) {
			return self::locked( $a['plan'], 'video' );
		}
		$ratio = preg_match( '/^\d+:\d+$/', $a['ratio'] ) ? str_replace( ':', ' / ', $a['ratio'] ) : '16 / 9';
		if ( 'presto' === $a['provider'] ) {
			return self::wrap( do_shortcode( '[presto_player id="' . absint( $a['id'] ) . '"]' ), 'video' );
		}
		if ( RINNOVOMA_Signer::is_embed( $a['provider'] ) ) {
			$src   = RINNOVOMA_Signer::url( $a['provider'], $a['id'] );
			$inner = '<iframe src="' . esc_url( $src ) . '" title="' . esc_attr( '' !== $a['title'] ? $a['title'] : __( 'Video', self::TD ) ) . '" loading="lazy" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" style="aspect-ratio:' . esc_attr( $ratio ) . '"></iframe>';
		} else {
			$src   = self::media_link( $a['provider'], $a['id'], get_current_user_id(), HOUR_IN_SECONDS );
			$inner = '<video controls playsinline preload="metadata" controlsList="nodownload noremoteplayback" disablePictureInPicture src="' . esc_url( $src ) . '"' . ( '' !== $a['poster'] ? ' poster="' . esc_url( $a['poster'] ) . '"' : '' ) . ' style="aspect-ratio:' . esc_attr( $ratio ) . '"></video>';
		}
		return self::wrap( $inner, 'video' );
	}

	/**
	 * [media_audio provider="bunnycdn|cloudfront|s3|file" id="…" plan="" title=""]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function audio( $atts ) {
		$a = self::atts( $atts, 'media_audio' );
		if ( ! RINNOVOMA_Access::has( get_current_user_id(), $a['plan'] ) ) {
			return self::locked( $a['plan'], 'audio' );
		}
		$src   = self::media_link( $a['provider'], $a['id'], get_current_user_id(), HOUR_IN_SECONDS );
		$inner = ( '' !== $a['title'] ? '<p class="rinnovoma-player__title">' . esc_html( $a['title'] ) . '</p>' : '' ) . '<audio controls preload="metadata" controlsList="nodownload" src="' . esc_url( $src ) . '"></audio>';
		return self::wrap( $inner, 'audio' );
	}

	/**
	 * Stream heartbeat from the player.
	 */
	public static function stream_heartbeat() {
		check_ajax_referer( 'rinnovoma_stream', 'nonce' );
		$id = isset( $_POST['stream'] ) ? sanitize_key( wp_unslash( $_POST['stream'] ) ) : '';
		if ( '' === $id || ! get_current_user_id() ) {
			wp_send_json_success( array( 'allowed' => true ) );
		}
		RINNOVOMA_Security::seen( get_current_user_id() );
		wp_send_json_success( RINNOVOMA_Access::heartbeat( get_current_user_id(), 'streams', $id, ! empty( $_POST['stop'] ) ) );
	}
}
