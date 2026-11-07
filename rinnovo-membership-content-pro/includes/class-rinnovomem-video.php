<?php
/**
 * Private video for members: [member_video provider="bunny" id="…"]. The player's address is only put in
 * the page for members, Bunny Stream links are signed and expire, and simultaneous streams per account
 * can be limited.
 *
 * Providers: bunny (Bunny Stream, signed embed tokens), vimeo (private / unlisted with hash, domain-level
 * privacy set in Vimeo), wistia (domain restrictions set in Wistia), youtube (unlisted), presto (Presto
 * Player video ID) and file (a protected file from the Media Library).
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Video.
 */
class RINNOVOMEM_Video {

	const TD = 'rinnovo-membership-content-pro';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_shortcode( 'member_video', array( __CLASS__, 'shortcode' ) );
		add_action( 'wp_ajax_rinnovomem_stream', array( __CLASS__, 'heartbeat' ) );
	}

	/**
	 * Signed Bunny Stream embed address.
	 *
	 * @param string $library Library ID.
	 * @param string $video   Video ID.
	 * @return string
	 */
	public static function bunny_url( $library, $video ) {
		$url = 'https://iframe.mediadelivery.net/embed/' . rawurlencode( $library ) . '/' . rawurlencode( $video );
		$key = (string) RINNOVOMEM_Settings::get( 'bunny_key' );
		if ( '' === $key ) {
			return $url;
		}
		$expires = time() + 4 * HOUR_IN_SECONDS;
		return add_query_arg(
			array(
				'token'   => hash( 'sha256', $key . $video . $expires ),
				'expires' => $expires,
			),
			$url
		);
	}

	/**
	 * The player for a member.
	 *
	 * @param array $atts Attributes.
	 * @return string HTML.
	 */
	private static function player( $atts ) {
		$id    = trim( (string) $atts['id'] );
		$ratio = preg_match( '/^\d+:\d+$/', $atts['ratio'] ) ? str_replace( ':', ' / ', $atts['ratio'] ) : '16 / 9';
		$title = '' !== $atts['title'] ? $atts['title'] : __( 'Video', self::TD );
		$src   = '';
		switch ( $atts['provider'] ) {
			case 'bunny':
				$library = '' !== $atts['library'] ? $atts['library'] : (string) RINNOVOMEM_Settings::get( 'bunny_library' );
				$src     = self::bunny_url( $library, $id );
				break;
			case 'vimeo':
				$parts = explode( '/', $id );
				$src   = 'https://player.vimeo.com/video/' . rawurlencode( $parts[0] ) . '?dnt=1' . ( isset( $parts[1] ) ? '&h=' . rawurlencode( $parts[1] ) : '' );
				break;
			case 'wistia':
				$src = 'https://fast.wistia.net/embed/iframe/' . rawurlencode( $id ) . '?videoFoam=true';
				break;
			case 'youtube':
				$src = 'https://www.youtube-nocookie.com/embed/' . rawurlencode( $id ) . '?rel=0&modestbranding=1';
				break;
			case 'presto':
				return do_shortcode( '[presto_player id="' . absint( $id ) . '"]' );
			case 'file':
				$url = RINNOVOMEM_Files::is_protected( absint( $id ) ) ? RINNOVOMEM_Files::serve_url( absint( $id ) ) : wp_get_attachment_url( absint( $id ) );
				return '<video class="rinnovomem-video__file" controls controlsList="nodownload" preload="metadata" src="' . esc_url( (string) $url ) . '" style="aspect-ratio:' . esc_attr( $ratio ) . '"></video>';
		}
		if ( '' === $src ) {
			return '';
		}
		return '<iframe src="' . esc_url( $src ) . '" title="' . esc_attr( $title ) . '" loading="lazy" allow="accelerometer; gyroscope; autoplay; encrypted-media; picture-in-picture; fullscreen" allowfullscreen referrerpolicy="strict-origin-when-cross-origin" style="aspect-ratio:' . esc_attr( $ratio ) . '"></iframe>';
	}

	/**
	 * [member_video provider="bunny|vimeo|wistia|youtube|presto|file" id="…" tier="" drip="" library="" poster="" title=""]
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'provider' => 'bunny',
				'id'       => '',
				'tier'     => '',
				'drip'     => 0,
				'date'     => '',
				'library'  => '',
				'poster'   => '',
				'title'    => '',
				'ratio'    => '16:9',
			),
			$atts,
			'member_video'
		);
		$atts['provider'] = sanitize_key( $atts['provider'] );
		// The video's own tier, else the rule of the page it is on, else the lowest paid tier.
		$rule = '' !== $atts['tier'] ? array(
			'tier' => sanitize_key( $atts['tier'] ),
			'drip' => max( 0, (int) $atts['drip'] ),
			'date' => preg_match( '/^\d{4}-\d{2}-\d{2}$/', $atts['date'] ) ? (int) strtotime( $atts['date'] . ' 00:00:00 ' . wp_timezone_string() ) : 0,
		) : RINNOVOMEM_Rules::requirement( (int) get_the_ID() );
		if ( ! $rule ) {
			$all  = RINNOVOMEM_Tiers::all();
			$rule = array(
				'tier' => $all ? (string) key( $all ) : RINNOVOMEM_Tiers::FREE,
				'drip' => 0,
				'date' => 0,
			);
		}
		$result = RINNOVOMEM_Rules::check( $rule );
		$style  = '' !== $atts['poster'] ? ' style="background-image:url(' . esc_url( $atts['poster'] ) . ')"' : '';
		if ( ! $result['ok'] ) {
			return '<div class="rinnovomem-video rinnovomem-video--locked"' . $style . '>' . RINNOVOMEM_Paywall::box( $result, 'video' ) . '</div>';
		}
		$limit = (int) RINNOVOMEM_Settings::get( 'max_streams' );
		$attrs = '';
		if ( $limit > 0 && is_user_logged_in() && ! current_user_can( 'edit_others_posts' ) ) {
			wp_enqueue_script( 'rinnovomem-streams', RINNOVOMEM_URL . 'assets/js/streams.js', array(), RINNOVOMEM_VERSION, true );
			wp_localize_script(
				'rinnovomem-streams',
				'rinnovomemStreams',
				array(
					'ajax'    => admin_url( 'admin-ajax.php' ),
					'nonce'   => wp_create_nonce( 'rinnovomem_stream' ),
					/* translators: %d: number of streams. */
					'message' => sprintf( _n( 'Your account is already playing %d video on another device. Stop it there to watch here.', 'Your account is already playing %d videos on other devices. Stop one of them to watch here.', $limit, self::TD ), $limit ),
				)
			);
			$attrs = ' data-rinnovomem-stream="' . esc_attr( wp_generate_uuid4() ) . '"';
		}
		return '<div class="rinnovomem-video"' . $attrs . '>' . self::player( $atts ) . '</div>';
	}

	/**
	 * Heartbeat of a playing video: one stream per page, the oldest streams of an account win.
	 */
	public static function heartbeat() {
		check_ajax_referer( 'rinnovomem_stream', 'nonce' );
		$user  = get_current_user_id();
		$limit = (int) RINNOVOMEM_Settings::get( 'max_streams' );
		$id    = isset( $_POST['stream'] ) ? sanitize_key( wp_unslash( $_POST['stream'] ) ) : '';
		if ( ! $user || $limit < 1 || '' === $id ) {
			wp_send_json_success( array( 'play' => true ) );
		}
		$streams = (array) get_user_meta( $user, '_rinnovomem_streams', true );
		$now     = time();
		foreach ( $streams as $key => $stream ) {
			if ( ! is_array( $stream ) || $now - (int) $stream['seen'] > 90 ) {
				unset( $streams[ $key ] );
			}
		}
		if ( ! empty( $_POST['stop'] ) ) {
			unset( $streams[ $id ] );
			update_user_meta( $user, '_rinnovomem_streams', $streams );
			wp_send_json_success( array( 'play' => false ) );
		}
		if ( ! isset( $streams[ $id ] ) ) {
			$streams[ $id ] = array( 'start' => $now );
		}
		$streams[ $id ]['seen'] = $now;
		uasort(
			$streams,
			static function ( $a, $b ) {
				return (int) $a['start'] - (int) $b['start'];
			}
		);
		$allowed = array_slice( array_keys( $streams ), 0, $limit );
		update_user_meta( $user, '_rinnovomem_streams', $streams );
		wp_send_json_success( array( 'play' => in_array( $id, $allowed, true ) ) );
	}
}
