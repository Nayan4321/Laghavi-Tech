<?php
/**
 * The media authorization pipeline. Every play or download goes through the same steps:
 *
 * 1. Identity: logged in, account not locked.
 * 2. Entitlement: an active plan that includes the item (plan rank, client library permission, publish date,
 *    Membership Content Pro tier when that plugin is active), or the item's free preview.
 * 3. Quota and limits: downloads quota, streams and devices at once, rate limit.
 * 4. Protected access: a short-lived signed link or provider token; storage keys never leave the server.
 * 5. Tracking: playback, downloads and denied attempts go to the activity log (history, resume, analytics,
 *    audit trail).
 *
 * Signed links are not DRM: a determined subscriber can still record their screen. DRM-capable streaming
 * (Widevine, FairPlay) is a future integration.
 *
 * @package RinnovoMediaArchives
 */

defined( 'ABSPATH' ) || exit;

/**
 * Pipeline and activity.
 */
class RINNOVOMA_Pipeline {

	const TD = 'rinnovo-media-archives-pro';
	const DB = '1';

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( get_option( 'rinnovoma_events_db' ) !== self::DB ) {
			self::install();
		}
		add_filter( 'rest_pre_dispatch', array( __CLASS__, 'rest_rate_limit' ), 5, 3 );
		add_action( 'init', array( __CLASS__, 'device_cookie' ), 1 );
		add_action( 'wp_ajax_rinnovoma_progress', array( __CLASS__, 'progress' ) );
	}

	/**
	 * Activity table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rinnovoma_events';
	}

	/**
	 * Create it.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				media_id bigint(20) unsigned NOT NULL DEFAULT 0,
				action varchar(16) NOT NULL,
				seconds int(11) NOT NULL DEFAULT 0,
				duration int(11) NOT NULL DEFAULT 0,
				device varchar(16) NOT NULL DEFAULT '',
				ip_hash varchar(16) NOT NULL DEFAULT '',
				detail varchar(190) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_action (user_id, action, created_at),
				KEY media (media_id, action)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'rinnovoma_events_db', self::DB, false );
	}

	/**
	 * A device ID in a cookie (devices are told apart by more than their IP address).
	 */
	public static function device_cookie() {
		if ( is_user_logged_in() && empty( $_COOKIE['rinnovoma_device'] ) && ! headers_sent() ) {
			$id = substr( wp_generate_uuid4(), 0, 16 );
			setcookie( 'rinnovoma_device', $id, time() + YEAR_IN_SECONDS, COOKIEPATH ? COOKIEPATH : '/', COOKIE_DOMAIN, is_ssl(), true );
			$_COOKIE['rinnovoma_device'] = $id;
		}
	}

	/**
	 * This device.
	 *
	 * @return string
	 */
	public static function device() {
		return isset( $_COOKIE['rinnovoma_device'] ) ? substr( sanitize_key( wp_unslash( $_COOKIE['rinnovoma_device'] ) ), 0, 16 ) : '';
	}

	/**
	 * Record an event.
	 *
	 * @param int    $user_id  User.
	 * @param int    $media_id Media item.
	 * @param string $action   play, progress, download, denied, share.
	 * @param int    $seconds  Position.
	 * @param int    $duration Length.
	 * @param string $detail   Detail.
	 */
	public static function event( $user_id, $media_id, $action, $seconds = 0, $duration = 0, $detail = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->insert(
			self::table(),
			array(
				'user_id'    => (int) $user_id,
				'media_id'   => (int) $media_id,
				'action'     => substr( $action, 0, 16 ),
				'seconds'    => (int) $seconds,
				'duration'   => (int) $duration,
				'device'     => self::device(),
				'ip_hash'    => substr( hash_hmac( 'sha256', RINNOVOMA_Security::ip(), wp_salt( 'nonce' ) ), 0, 16 ),
				'detail'     => substr( $detail, 0, 190 ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Allow at most $max hits per $window seconds for a key.
	 *
	 * @param string $key    Key.
	 * @param int    $max    Hits.
	 * @param int    $window Seconds.
	 * @return bool Limited.
	 */
	public static function rate_limited( $key, $max = 60, $window = 60 ) {
		$k     = 'rinnovoma_rl_' . md5( $key );
		$count = (int) get_transient( $k );
		if ( $count >= $max ) {
			return true;
		}
		set_transient( $k, $count + 1, $window );
		return false;
	}

	/**
	 * Rate limit on the plugin's API.
	 *
	 * @param mixed           $result  Result.
	 * @param WP_REST_Server  $server  Server.
	 * @param WP_REST_Request $request Request.
	 * @return mixed
	 */
	public static function rest_rate_limit( $result, $server, $request ) {
		if ( 0 !== strpos( $request->get_route(), '/rinnovoma/' ) ) {
			return $result;
		}
		$limit = max( 10, (int) RINNOVOMA_Plans::get( 'api_rate' ) );
		if ( self::rate_limited( 'api|' . RINNOVOMA_Security::ip(), $limit, 60 ) ) {
			return new WP_Error( 'rinnovoma_rate', __( 'Too many requests. Please slow down.', self::TD ), array( 'status' => 429 ) );
		}
		return $result;
	}

	/**
	 * Run the pipeline for a media item.
	 *
	 * @param int    $user_id  User.
	 * @param int    $media_id Media item.
	 * @param string $action   play or download.
	 * @return array ok, reason, preview (bool), url.
	 */
	public static function authorize( $user_id, $media_id, $action = 'play' ) {
		$out  = array(
			'ok'      => false,
			'reason'  => '',
			'preview' => false,
			'url'     => '',
		);
		$item = RINNOVOMA_Library::item( $media_id );
		if ( ! $item ) {
			$out['reason'] = 'missing';
			return $out;
		}
		$preview = '' !== $item['preview_id'];
		// 1. Identity.
		if ( ! $user_id ) {
			$out['reason']  = 'login';
			$out['preview'] = $preview && 'play' === $action;
			return self::deny( $out, $user_id, $media_id );
		}
		if ( get_user_meta( $user_id, '_rinnovoma_locked', true ) ) {
			$out['reason'] = 'locked';
			return self::deny( $out, $user_id, $media_id );
		}
		// 2. Entitlement.
		if ( ! user_can( $user_id, 'manage_woocommerce' ) ) {
			if ( 'publish' !== get_post_status( $media_id ) ) {
				$out['reason'] = 'scheduled';
				return self::deny( $out, $user_id, $media_id );
			}
			if ( ! RINNOVOMA_Library::client_ok( $user_id, $media_id ) ) {
				$out['reason'] = 'client';
				return self::deny( $out, $user_id, $media_id );
			}
			$entitled = RINNOVOMA_Access::has( $user_id, $item['plan'] );
			if ( ! $entitled && '' !== $item['tier'] && class_exists( 'RINNOVOMEM_Members' ) ) {
				// Shared entitlement: a Membership Content Pro tier may unlock it too.
				$entitled = RINNOVOMEM_Members::has( $user_id, $item['tier'] );
			}
			if ( ! $entitled ) {
				$out['reason']  = 'plan';
				$out['preview'] = $preview && 'play' === $action;
				return self::deny( $out, $user_id, $media_id );
			}
		}
		// 3. Quota and rate.
		if ( self::rate_limited( 'media|' . $user_id, 120, 60 ) ) {
			$out['reason'] = 'rate';
			return self::deny( $out, $user_id, $media_id );
		}
		if ( 'download' === $action ) {
			if ( ! $item['download'] ) {
				$out['reason'] = 'no_download';
				return self::deny( $out, $user_id, $media_id );
			}
			$report = RINNOVOMA_Access::usage_report( $user_id );
			if ( isset( $report['downloads'] ) && ! self::downloaded( $user_id, $media_id ) ) {
				$used = RINNOVOMA_Access::use_quota( $user_id, 'downloads', 1 );
				if ( is_wp_error( $used ) ) {
					$out['reason'] = 'quota';
					return self::deny( $out, $user_id, $media_id );
				}
			}
		}
		// 4. Protected access (short-lived, for this user).
		$out['ok']  = true;
		$out['url'] = RINNOVOMA_Signer::is_embed( $item['provider'] ) && 'play' === $action ? RINNOVOMA_Signer::url( $item['provider'], $item['id'] ) : RINNOVOMA_Player::media_link( $item['provider'], $item['id'], $user_id, 'download' === $action ? 10 * MINUTE_IN_SECONDS : HOUR_IN_SECONDS );
		// 5. Tracking.
		self::event( $user_id, $media_id, 'download' === $action ? 'download' : 'play' );
		return $out;
	}

	/**
	 * Log a refusal and return it.
	 *
	 * @param array $out      Result.
	 * @param int   $user_id  User.
	 * @param int   $media_id Media.
	 * @return array
	 */
	private static function deny( $out, $user_id, $media_id ) {
		if ( $user_id && ! in_array( $out['reason'], array( 'plan', 'login' ), true ) ) {
			self::event( $user_id, $media_id, 'denied', 0, 0, $out['reason'] );
		}
		return $out;
	}

	/**
	 * Whether a user downloaded an item before (downloading again is free).
	 *
	 * @param int $user_id  User.
	 * @param int $media_id Media.
	 * @return bool
	 */
	public static function downloaded( $user_id, $media_id ) {
		global $wpdb;
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE user_id = %d AND media_id = %d AND action = 'download' LIMIT 1", self::table(), $user_id, $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Resume point and watched state.
	 *
	 * @param int $user_id  User.
	 * @param int $media_id Media.
	 * @return array seconds, duration, done.
	 */
	public static function position( $user_id, $media_id ) {
		global $wpdb;
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT seconds, duration FROM %i WHERE user_id = %d AND media_id = %d AND action = 'progress' ORDER BY id DESC LIMIT 1", self::table(), $user_id, $media_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$s   = $row ? (int) $row->seconds : 0;
		$d   = $row ? (int) $row->duration : 0;
		return array(
			'seconds'  => $s,
			'duration' => $d,
			'done'     => $d > 0 && $s / $d >= 0.9,
		);
	}

	/**
	 * Save progress from the player.
	 */
	public static function progress() {
		check_ajax_referer( 'rinnovoma_progress', 'nonce' );
		$user  = get_current_user_id();
		$media = isset( $_POST['media'] ) ? absint( $_POST['media'] ) : 0;
		if ( ! $user || ! $media || self::rate_limited( 'progress|' . $user, 30, 60 ) ) {
			wp_send_json_error();
		}
		global $wpdb;
		$t = isset( $_POST['t'] ) ? (int) $_POST['t'] : 0;
		$d = isset( $_POST['d'] ) ? (int) $_POST['d'] : 0;
		// One progress row per member and item: update it.
		$id = (int) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE user_id = %d AND media_id = %d AND action = 'progress' LIMIT 1", self::table(), $user, $media ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		if ( $id ) {
			$wpdb->update( self::table(), array( 'seconds' => max( 0, $t ), 'duration' => max( 0, $d ), 'created_at' => current_time( 'mysql', true ) ), array( 'id' => $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		} else {
			self::event( $user, $media, 'progress', $t, $d );
		}
		wp_send_json_success();
	}

	/**
	 * Items a member watched or listened to most recently (continue watching first).
	 *
	 * @param int  $user_id  User.
	 * @param int  $limit    Items.
	 * @param bool $unfinished Only unfinished ones.
	 * @return array[] media, seconds, duration, at.
	 */
	public static function recent( $user_id, $limit = 12, $unfinished = false ) {
		global $wpdb;
		$rows = (array) $wpdb->get_results( $wpdb->prepare( "SELECT media_id, seconds, duration, created_at FROM %i WHERE user_id = %d AND action = 'progress' ORDER BY created_at DESC LIMIT %d", self::table(), $user_id, $limit * 2 ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$out  = array();
		foreach ( $rows as $r ) {
			$done = (int) $r->duration > 0 && (int) $r->seconds / (int) $r->duration >= 0.9;
			if ( ( $unfinished && $done ) || 'publish' !== get_post_status( (int) $r->media_id ) ) {
				continue;
			}
			$out[] = array(
				'media'    => (int) $r->media_id,
				'seconds'  => (int) $r->seconds,
				'duration' => (int) $r->duration,
				'at'       => strtotime( $r->created_at . ' UTC' ),
			);
		}
		return array_slice( $out, 0, $limit );
	}

	/**
	 * Downloads of a member.
	 *
	 * @param int $user_id User.
	 * @param int $limit   Rows.
	 * @return object[]
	 */
	public static function downloads( $user_id, $limit = 20 ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT media_id, created_at FROM %i WHERE user_id = %d AND action = 'download' ORDER BY id DESC LIMIT %d", self::table(), $user_id, $limit ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Media analytics for the last days.
	 *
	 * @param int $days Days.
	 * @return array totals, top.
	 */
	public static function analytics( $days = 30 ) {
		global $wpdb;
		$since = gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS );
		// phpcs:disable WordPress.DB.DirectDatabaseQuery -- Own table, admin screen.
		$totals = (array) $wpdb->get_results( $wpdb->prepare( 'SELECT action, COUNT(*) n, COUNT(DISTINCT user_id) u FROM %i WHERE created_at >= %s GROUP BY action', self::table(), $since ), OBJECT_K );
		$top    = (array) $wpdb->get_results( $wpdb->prepare( "SELECT media_id, SUM(action = 'play') plays, SUM(action = 'download') downloads, COUNT(DISTINCT user_id) people FROM %i WHERE created_at >= %s AND action IN ('play','download') GROUP BY media_id ORDER BY plays + downloads DESC LIMIT 20", self::table(), $since ) );
		$denied = (array) $wpdb->get_results( $wpdb->prepare( "SELECT user_id, media_id, detail, device, created_at FROM %i WHERE action = 'denied' ORDER BY id DESC LIMIT 30", self::table() ) );
		// phpcs:enable
		return array(
			'totals' => $totals,
			'top'    => $top,
			'denied' => $denied,
		);
	}

	/**
	 * Share link for one item (a client without an account; expires).
	 *
	 * @param int $media_id Media.
	 * @param int $days     Valid days.
	 * @return string
	 */
	public static function share_link( $media_id, $days = 7 ) {
		$exp = time() + max( 1, (int) $days ) * DAY_IN_SECONDS;
		return add_query_arg(
			array(
				'rinnovoma_share' => (int) $media_id,
				'exp'             => $exp,
				'sig'             => substr( hash_hmac( 'sha256', 'share|' . (int) $media_id . '|' . $exp, (string) RINNOVOMA_Plans::get( 'jwt_secret' ) . wp_salt( 'auth' ) ), 0, 32 ),
			),
			(string) get_permalink( $media_id )
		);
	}

	/**
	 * Whether the current request carries a valid share link for an item.
	 *
	 * @param int $media_id Media.
	 * @return bool
	 */
	public static function shared( $media_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Signed link.
		if ( empty( $_GET['rinnovoma_share'] ) || (int) $_GET['rinnovoma_share'] !== (int) $media_id || empty( $_GET['exp'] ) || empty( $_GET['sig'] ) ) {
			return false;
		}
		$exp = absint( $_GET['exp'] );
		$sig = sanitize_key( wp_unslash( $_GET['sig'] ) );
		// phpcs:enable
		return $exp > time() && hash_equals( substr( hash_hmac( 'sha256', 'share|' . (int) $media_id . '|' . $exp, (string) RINNOVOMA_Plans::get( 'jwt_secret' ) . wp_salt( 'auth' ) ), 0, 32 ), $sig );
	}
}
