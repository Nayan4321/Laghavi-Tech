<?php
/**
 * Download credits and quotas: a resource library (photos, templates, PSD, video…) where members download with
 * credits from their plan (for example Starter 10 a month, Silver 50, Gold unlimited with fair use) and with
 * credit packs they buy. Plan credits reset each period (unused ones can carry over); bought credits never
 * expire with the reset. Credits come from one shared wallet or from a quota per category (photos 30, PSD 10…).
 *
 * Every change is a row in the credit ledger (grant, download, refund, top-up, expiry), deducted under a lock so
 * two downloads at once can't spend the same credit. Files are never reachable by a public address.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Credits.
 */
class RINNOVOMEM_Credits {

	const TD     = 'rinnovo-membership-content-pro';
	const TYPE   = 'rinnovomem_resource';
	const TAX    = 'rinnovomem_resource_cat';
	const PLANS  = 'rinnovomem_download_plans';
	const DB     = '1';
	const TOPUP  = '_rinnovomem_topup';

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( get_option( 'rinnovomem_credits_db' ) !== self::DB ) {
			self::install();
		}
		add_action( 'init', array( __CLASS__, 'register' ) );
		add_action( 'init', array( __CLASS__, 'download' ), 25 );
		add_filter( 'rinnovomem_admin_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'rinnovomem_admin_tab_downloads', array( __CLASS__, 'render' ) );
		add_action( 'rinnovomem_admin_save_downloads', array( __CLASS__, 'save' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'meta_box' ) );
		add_action( 'save_post_' . self::TYPE, array( __CLASS__, 'save_resource' ), 10, 2 );
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'topup_field' ) );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_topup_field' ) );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'topup_paid' ), 30 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'topup_paid' ), 30 );
		add_action( 'woocommerce_order_status_refunded', array( __CLASS__, 'topup_refunded' ), 30 );
		add_shortcode( 'rinnovo_resources', array( __CLASS__, 'library' ) );
		add_shortcode( 'rinnovo_credits', array( __CLASS__, 'balance_shortcode' ) );
		add_filter( 'the_content', array( __CLASS__, 'single' ), 20 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'admin_assets' ) );
	}

	/**
	 * Credit ledger table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rinnovomem_credits';
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
				user_id bigint(20) unsigned NOT NULL,
				bucket varchar(10) NOT NULL,
				cat bigint(20) unsigned NOT NULL DEFAULT 0,
				delta int(11) NOT NULL,
				reason varchar(12) NOT NULL,
				ref bigint(20) unsigned NOT NULL DEFAULT 0,
				period varchar(32) NOT NULL DEFAULT '',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY user_bucket (user_id, bucket, cat),
				KEY user_reason (user_id, reason, created_at)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'rinnovomem_credits_db', self::DB, false );
	}

	/**
	 * Resource type and categories.
	 */
	public static function register() {
		register_post_type(
			self::TYPE,
			array(
				'labels'       => array(
					'name'          => __( 'Resources', self::TD ),
					'singular_name' => __( 'Resource', self::TD ),
					'add_new_item'  => __( 'Add resource', self::TD ),
					'edit_item'     => __( 'Edit resource', self::TD ),
					'menu_name'     => __( 'Resources', self::TD ),
				),
				'public'       => true,
				'show_in_menu' => true,
				'menu_icon'    => 'dashicons-download',
				'supports'     => array( 'title', 'editor', 'thumbnail', 'excerpt' ),
				'has_archive'  => false,
				'rewrite'      => array( 'slug' => 'resource' ),
				'show_in_rest' => true,
			)
		);
		register_taxonomy(
			self::TAX,
			self::TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Resource categories', self::TD ),
					'singular_name' => __( 'Resource category', self::TD ),
				),
				'hierarchical'      => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'rewrite'           => array( 'slug' => 'resources' ),
			)
		);
	}

	// ---------------------------------------------------------------- Plans.

	/**
	 * Download plans.
	 *
	 * @return array[] id, name, products, tiers, allowance, period, carry, carry_max, per_day, quotas, types.
	 */
	public static function plans() {
		$out = array();
		foreach ( (array) get_option( self::PLANS, array() ) as $p ) {
			if ( ! is_array( $p ) || empty( $p['id'] ) ) {
				continue;
			}
			$out[ $p['id'] ] = wp_parse_args(
				$p,
				array(
					'name'      => '',
					'products'  => array(),
					'tiers'     => array(),
					'allowance' => 10,
					'period'    => 'month',
					'carry'     => 0,
					'carry_max' => 0,
					'per_day'   => 0,
					'quotas'    => array(),
					'types'     => '',
				)
			);
		}
		return $out;
	}

	/**
	 * The plan of a user (the most generous one they hold), with the subscription behind it.
	 *
	 * @param int $user_id User.
	 * @return array|null Plan + sub.
	 */
	public static function plan_for( $user_id ) {
		if ( ! $user_id ) {
			return null;
		}
		$best = null;
		$subs = RINNOVO_Subscriptions::for_user( $user_id );
		foreach ( self::plans() as $plan ) {
			$sub = null;
			$ok  = false;
			foreach ( $subs as $s ) {
				if ( RINNOVOMEM_Members::sub_gives_access( $s ) && ( in_array( (int) $s->product_id, array_map( 'intval', $plan['products'] ), true ) || in_array( (int) wp_get_post_parent_id( (int) $s->product_id ), array_map( 'intval', $plan['products'] ), true ) ) ) {
					$ok  = true;
					$sub = $s;
				}
			}
			foreach ( (array) $plan['tiers'] as $tier ) {
				if ( RINNOVOMEM_Members::has( $user_id, $tier ) ) {
					$ok = true;
				}
			}
			if ( ! $ok ) {
				continue;
			}
			$plan['sub'] = $sub;
			$score       = 0 === (int) $plan['allowance'] ? PHP_INT_MAX : (int) $plan['allowance'];
			if ( ! $best || $score > ( 0 === (int) $best['allowance'] ? PHP_INT_MAX : (int) $best['allowance'] ) ) {
				$best = $plan;
			}
		}
		return $best;
	}

	/**
	 * Key and end of the current period of a plan.
	 *
	 * @param array $plan Plan.
	 * @return array key, ends.
	 */
	public static function period( $plan ) {
		$tz  = wp_timezone();
		$now = new DateTimeImmutable( 'now', $tz );
		switch ( $plan['period'] ) {
			case 'week':
				$start = $now->modify( 'monday this week' )->setTime( 0, 0 );
				return array( $plan['id'] . ':w' . $start->format( 'oW' ), $start->modify( '+1 week' )->getTimestamp() );
			case 'year':
				return array( $plan['id'] . ':y' . $now->format( 'Y' ), $now->setDate( (int) $now->format( 'Y' ) + 1, 1, 1 )->setTime( 0, 0 )->getTimestamp() );
			case 'cycle':
				if ( ! empty( $plan['sub'] ) && $plan['sub']->next_payment ) {
					return array( $plan['id'] . ':c' . gmdate( 'Ymd', strtotime( $plan['sub']->next_payment . ' UTC' ) ), strtotime( $plan['sub']->next_payment . ' UTC' ) );
				}
				// Falls back to months.
			default:
				$start = $now->modify( 'first day of this month' )->setTime( 0, 0 );
				return array( $plan['id'] . ':m' . $start->format( 'Y-m' ), $start->modify( '+1 month' )->getTimestamp() );
		}
	}

	/**
	 * Hold the user's credit lock (one change at a time).
	 *
	 * @param int $user_id User.
	 * @return bool
	 */
	private static function lock( $user_id ) {
		global $wpdb;
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, 10)', 'rinnovomem_cr_' . (int) $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Named lock.
	}

	/**
	 * Release it.
	 *
	 * @param int $user_id User.
	 */
	private static function unlock( $user_id ) {
		global $wpdb;
		$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', 'rinnovomem_cr_' . (int) $user_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Named lock.
	}

	/**
	 * Add a ledger row.
	 *
	 * @param int    $user_id User.
	 * @param string $bucket  plan or bought.
	 * @param int    $cat     Category (0 = wallet).
	 * @param int    $delta   Credits (+/-).
	 * @param string $reason  grant, expire, download, refund, topup, adjust.
	 * @param int    $ref     Resource, order item…
	 * @param string $period  Period key.
	 */
	public static function row( $user_id, $bucket, $cat, $delta, $reason, $ref = 0, $period = '' ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$wpdb->insert(
			self::table(),
			array(
				'user_id'    => (int) $user_id,
				'bucket'     => $bucket,
				'cat'        => (int) $cat,
				'delta'      => (int) $delta,
				'reason'     => $reason,
				'ref'        => (int) $ref,
				'period'     => substr( $period, 0, 32 ),
				'created_at' => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Raw balance of a bucket.
	 *
	 * @param int    $user_id User.
	 * @param string $bucket  Bucket.
	 * @param int    $cat     Category.
	 * @return int
	 */
	private static function sum( $user_id, $bucket, $cat = 0 ) {
		global $wpdb;
		return (int) $wpdb->get_var( $wpdb->prepare( 'SELECT COALESCE(SUM(delta),0) FROM %i WHERE user_id = %d AND bucket = %s AND cat = %d', self::table(), $user_id, $bucket, $cat ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Start the period: plan credits of the last period expire (or carry over), this period's are granted.
	 * Runs lazily whenever credits are read, so no schedule is needed.
	 *
	 * @param int        $user_id User.
	 * @param array|null $plan    Plan.
	 */
	public static function refresh( $user_id, $plan ) {
		global $wpdb;
		if ( ! $plan || 0 === (int) $plan['allowance'] && ! $plan['quotas'] ) {
			return;
		}
		list( $key ) = self::period( $plan );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$done = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE user_id = %d AND reason = 'grant' AND period = %s", self::table(), $user_id, $key ) );
		if ( $done || ! self::lock( $user_id ) ) {
			return;
		}
		try {
			$done = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE user_id = %d AND reason = 'grant' AND period = %s", self::table(), $user_id, $key ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			if ( $done ) {
				return;
			}
			$grants = 'category' === RINNOVOMEM_Settings::get( 'credit_mode' ) && $plan['quotas'] ? $plan['quotas'] : array( 0 => (int) $plan['allowance'] );
			// Old plan credits: expire, or carry over up to the cap.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$cats = array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT cat FROM %i WHERE user_id = %d AND bucket = 'plan'", self::table(), $user_id ) ) );
			foreach ( $cats as $cat ) {
				$left = self::sum( $user_id, 'plan', $cat );
				$keep = $plan['carry'] ? ( (int) $plan['carry_max'] ? min( $left, (int) $plan['carry_max'] ) : $left ) : 0;
				if ( $left - $keep > 0 ) {
					self::row( $user_id, 'plan', $cat, -( $left - $keep ), 'expire', 0, $key );
				}
			}
			foreach ( $grants as $cat => $n ) {
				if ( (int) $n > 0 ) {
					self::row( $user_id, 'plan', (int) $cat, (int) $n, 'grant', 0, $key );
				}
			}
		} finally {
			self::unlock( $user_id );
		}
	}

	/**
	 * Balances of a user.
	 *
	 * @param int $user_id User.
	 * @return array plan (null), unlimited, plan_credits (cat => n), bought, used (this period), resets, today.
	 */
	public static function balance( $user_id ) {
		global $wpdb;
		$plan = self::plan_for( $user_id );
		self::refresh( $user_id, $plan );
		$out = array(
			'plan'         => $plan,
			'unlimited'    => $plan && 0 === (int) $plan['allowance'] && ! ( 'category' === RINNOVOMEM_Settings::get( 'credit_mode' ) && $plan['quotas'] ),
			'plan_credits' => array(),
			'bought'       => self::sum( $user_id, 'bought' ),
			'used'         => 0,
			'resets'       => 0,
			'today'        => 0,
			'allowance'    => $plan ? (int) $plan['allowance'] : 0,
		);
		if ( $plan ) {
			list( $key, $ends ) = self::period( $plan );
			$out['resets']      = $ends;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			foreach ( (array) $wpdb->get_col( $wpdb->prepare( "SELECT DISTINCT cat FROM %i WHERE user_id = %d AND bucket = 'plan'", self::table(), $user_id ) ) as $cat ) {
				$out['plan_credits'][ (int) $cat ] = self::sum( $user_id, 'plan', (int) $cat );
			}
			$since = gmdate( 'Y-m-d H:i:s', $ends - self::period_length( $plan ) );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			$out['used'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM %i WHERE user_id = %d AND reason = 'download' AND created_at >= %s", self::table(), $user_id, $since ) );
		}
		$day = ( new DateTimeImmutable( 'today', wp_timezone() ) )->getTimestamp();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		$out['today'] = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(DISTINCT ref) FROM %i WHERE user_id = %d AND reason IN ('download','free') AND created_at >= %s", self::table(), $user_id, gmdate( 'Y-m-d H:i:s', $day ) ) );
		return $out;
	}

	/**
	 * Approximate length of a plan period.
	 *
	 * @param array $plan Plan.
	 * @return int Seconds.
	 */
	private static function period_length( $plan ) {
		$map = array(
			'week'  => WEEK_IN_SECONDS,
			'year'  => YEAR_IN_SECONDS,
			'cycle' => MONTH_IN_SECONDS,
		);
		return isset( $map[ $plan['period'] ] ) ? $map[ $plan['period'] ] : MONTH_IN_SECONDS;
	}

	/**
	 * Credits a resource costs.
	 *
	 * @param int $id Resource.
	 * @return int
	 */
	public static function cost( $id ) {
		$own = get_post_meta( $id, '_rinnovomem_cost', true );
		if ( '' !== (string) $own ) {
			return max( 0, (int) $own );
		}
		foreach ( wp_get_post_terms( $id, self::TAX ) as $term ) {
			$c = get_term_meta( $term->term_id, '_rinnovomem_cost', true );
			if ( '' !== (string) $c ) {
				return max( 0, (int) $c );
			}
		}
		return 1;
	}

	/**
	 * Category of a resource that has a quota in a plan (category mode).
	 *
	 * @param int        $id   Resource.
	 * @param array|null $plan Plan.
	 * @return int
	 */
	private static function quota_cat( $id, $plan ) {
		if ( ! $plan || 'category' !== RINNOVOMEM_Settings::get( 'credit_mode' ) || ! $plan['quotas'] ) {
			return 0;
		}
		foreach ( wp_get_post_terms( $id, self::TAX, array( 'fields' => 'ids' ) ) as $term_id ) {
			foreach ( array_merge( array( $term_id ), get_ancestors( $term_id, self::TAX ) ) as $tid ) {
				if ( isset( $plan['quotas'][ $tid ] ) ) {
					return (int) $tid;
				}
			}
		}
		return 0;
	}

	/**
	 * Whether this user downloaded a resource recently (re-downloads are free).
	 *
	 * @param int $user_id User.
	 * @param int $id      Resource.
	 * @return bool
	 */
	public static function downloaded( $user_id, $id ) {
		global $wpdb;
		$days = (int) RINNOVOMEM_Settings::get( 'redownload_days' );
		if ( ! $days ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		return (bool) $wpdb->get_var( $wpdb->prepare( "SELECT id FROM %i WHERE user_id = %d AND ref = %d AND reason = 'download' AND created_at >= %s LIMIT 1", self::table(), $user_id, $id, gmdate( 'Y-m-d H:i:s', time() - $days * DAY_IN_SECONDS ) ) );
	}

	/**
	 * Can this user download a resource now, and what would it cost?
	 *
	 * @param int $user_id User.
	 * @param int $id      Resource.
	 * @return array ok, cost, free (re-download), reason text.
	 */
	public static function can_download( $user_id, $id ) {
		$out = array(
			'ok'     => false,
			'cost'   => self::cost( $id ),
			'free'   => false,
			'reason' => '',
		);
		if ( ! $user_id ) {
			$out['reason'] = __( 'Log in to download.', self::TD );
			return $out;
		}
		$b    = self::balance( $user_id );
		$plan = $b['plan'];
		if ( ! $plan && $b['bought'] <= 0 ) {
			$out['reason'] = __( 'Downloads are for members with a download plan.', self::TD );
			return $out;
		}
		$file = (int) get_post_meta( $id, '_rinnovomem_file', true );
		if ( $plan && '' !== trim( (string) $plan['types'] ) && $file ) {
			$ext   = strtolower( pathinfo( (string) get_attached_file( $file ), PATHINFO_EXTENSION ) );
			$types = array_map( 'trim', explode( ',', strtolower( (string) $plan['types'] ) ) );
			if ( ! in_array( $ext, $types, true ) ) {
				/* translators: %s: file type. */
				$out['reason'] = sprintf( __( 'Your plan does not include %s files.', self::TD ), strtoupper( $ext ) );
				return $out;
			}
		}
		$per_day = $plan ? (int) $plan['per_day'] : 0;
		if ( $b['unlimited'] && ! $per_day ) {
			$per_day = (int) apply_filters( 'rinnovomem_fair_use_daily', 50 );
		}
		if ( self::downloaded( $user_id, $id ) ) {
			$out['ok']   = true;
			$out['free'] = true;
			return $out;
		}
		if ( $per_day && $b['today'] >= $per_day ) {
			/* translators: %d: downloads. */
			$out['reason'] = sprintf( __( 'You reached today\'s limit of %d downloads. Try again tomorrow.', self::TD ), $per_day );
			return $out;
		}
		if ( 0 === $out['cost'] || $b['unlimited'] ) {
			$out['ok'] = true;
			return $out;
		}
		$cat  = self::quota_cat( $id, $plan );
		$have = ( isset( $b['plan_credits'][ $cat ] ) ? max( 0, $b['plan_credits'][ $cat ] ) : 0 ) + max( 0, $b['bought'] );
		if ( $have < $out['cost'] ) {
			/* translators: 1: cost, 2: credits left. */
			$out['reason'] = sprintf( _n( 'This needs %1$d credit and you have %2$d. Buy a credit pack or wait for your next reset.', 'This needs %1$d credits and you have %2$d. Buy a credit pack or wait for your next reset.', $out['cost'], self::TD ), $out['cost'], $have );
			return $out;
		}
		$out['ok'] = true;
		return $out;
	}

	/**
	 * Take the credits for a download (plan credits first, then bought ones), atomically.
	 *
	 * @param int $user_id User.
	 * @param int $id      Resource.
	 * @return array|WP_Error Rows taken [bucket, cat, n].
	 */
	public static function charge( $user_id, $id ) {
		if ( ! self::lock( $user_id ) ) {
			return new WP_Error( 'busy', __( 'Please try again in a moment.', self::TD ) );
		}
		try {
			$check = self::can_download( $user_id, $id );
			if ( ! $check['ok'] ) {
				return new WP_Error( 'denied', $check['reason'] );
			}
			$b = self::balance( $user_id );
			if ( $check['free'] ) {
				self::row( $user_id, 'plan', 0, 0, 'free', $id );
				return array();
			}
			if ( 0 === $check['cost'] || $b['unlimited'] ) {
				self::row( $user_id, 'plan', 0, 0, 'download', $id );
				return array();
			}
			$need  = $check['cost'];
			$cat   = self::quota_cat( $id, $b['plan'] );
			$taken = array();
			$plan  = isset( $b['plan_credits'][ $cat ] ) ? max( 0, $b['plan_credits'][ $cat ] ) : 0;
			if ( $plan > 0 ) {
				$n = min( $plan, $need );
				self::row( $user_id, 'plan', $cat, -$n, 'download', $id );
				$taken[] = array( 'plan', $cat, $n );
				$need   -= $n;
			}
			if ( $need > 0 ) {
				self::row( $user_id, 'bought', 0, -$need, 'download', $id );
				$taken[] = array( 'bought', 0, $need );
			}
			return $taken;
		} finally {
			self::unlock( $user_id );
		}
	}

	/**
	 * Give credits back (failed or interrupted download).
	 *
	 * @param int   $user_id User.
	 * @param int   $id      Resource.
	 * @param array $taken   Rows taken.
	 */
	public static function refund( $user_id, $id, $taken ) {
		foreach ( (array) $taken as $t ) {
			self::row( $user_id, $t[0], $t[1], $t[2], 'refund', $id );
		}
	}

	/**
	 * Download address of a resource (only works for the logged-in member it was made for, for a day).
	 *
	 * @param int $id Resource.
	 * @return string
	 */
	public static function url( $id ) {
		return add_query_arg(
			array(
				'rinnovomem_res' => (int) $id,
				'_wpnonce'       => wp_create_nonce( 'rinnovomem_res_' . (int) $id ),
			),
			home_url( '/' )
		);
	}

	/**
	 * Handle a download: check, charge, stream, record.
	 */
	public static function download() {
		if ( empty( $_GET['rinnovomem_res'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Verified below.
			return;
		}
		$id = absint( $_GET['rinnovomem_res'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		if ( ! is_user_logged_in() ) {
			wp_safe_redirect( add_query_arg( 'redirect_to', rawurlencode( (string) get_permalink( $id ) ), wc_get_page_permalink( 'myaccount' ) ) );
			exit;
		}
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_GET['_wpnonce'] ) ), 'rinnovomem_res_' . $id ) ) {
			wp_die( esc_html__( 'This download link has expired. Please open the resource again.', self::TD ), '', array( 'response' => 403 ) );
		}
		$file = (int) get_post_meta( $id, '_rinnovomem_file', true );
		$path = $file ? get_attached_file( $file, true ) : '';
		if ( self::TYPE !== get_post_type( $id ) || 'publish' !== get_post_status( $id ) || ! $path || ! is_readable( $path ) ) {
			wp_die( esc_html__( 'This file is not available right now.', self::TD ), '', array( 'response' => 404 ) );
		}
		$user  = get_current_user_id();
		$taken = self::charge( $user, $id );
		if ( is_wp_error( $taken ) ) {
			wp_die( esc_html( $taken->get_error_message() ), esc_html__( 'Download', self::TD ), array( 'response' => 403, 'back_link' => true ) );
		}
		RINNOVOMEM_Ledger::log( $user, $id, 'download', get_the_title( $id ) );
		$ok = self::stream( $path );
		if ( ! $ok && RINNOVOMEM_Settings::get( 'refund_failed' ) ) {
			self::refund( $user, $id, $taken );
		}
		exit;
	}

	/**
	 * Send the file (private, never cached, no public address).
	 *
	 * @param string $path File.
	 * @return bool Fully sent.
	 */
	private static function stream( $path ) {
		ignore_user_abort( true );
		$type = wp_check_filetype( $path );
		nocache_headers();
		header( 'Cache-Control: private, no-store, max-age=0' );
		header( 'CDN-Cache-Control: no-store' );
		header( 'X-Robots-Tag: noindex' );
		header( 'Content-Type: ' . ( $type['type'] ? $type['type'] : 'application/octet-stream' ) );
		header( 'Content-Disposition: attachment; filename="' . rawurlencode( basename( $path ) ) . '"' );
		header( 'Content-Length: ' . filesize( $path ) );
		$mode = RINNOVOMEM_Settings::get( 'file_mode' );
		if ( 'xsendfile' === $mode ) {
			header( 'X-Sendfile: ' . $path );
			return true;
		}
		if ( 'xaccel' === $mode ) {
			header( 'X-Accel-Redirect: /' . ltrim( str_replace( ABSPATH, '', $path ), '/' ) );
			return true;
		}
		while ( ob_get_level() ) {
			ob_end_clean();
		}
		$h = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Streaming.
		if ( ! $h ) {
			return false;
		}
		while ( ! feof( $h ) ) {
			echo fread( $h, 1048576 ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped, WordPress.WP.AlternativeFunctions.file_system_operations_fread -- File bytes.
			flush();
			if ( connection_aborted() ) {
				fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming.
				return false;
			}
		}
		fclose( $h ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Streaming.
		return true;
	}

	// ---------------------------------------------------------------- Top-ups.

	/**
	 * "Download credits" on simple products (credit packs).
	 */
	public static function topup_field() {
		woocommerce_wp_text_input(
			array(
				'id'          => self::TOPUP,
				'label'       => __( 'Download credits', self::TD ),
				'type'        => 'number',
				'description' => __( 'Credit pack: buying this product adds these download credits (they never expire with the monthly reset).', self::TD ),
				'desc_tip'    => true,
			)
		);
	}

	/**
	 * Save it.
	 *
	 * @param int $product_id Product.
	 */
	public static function save_topup_field( $product_id ) {
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) ) {
			return;
		}
		$n = isset( $_POST[ self::TOPUP ] ) ? absint( $_POST[ self::TOPUP ] ) : 0;
		update_post_meta( $product_id, self::TOPUP, $n ? $n : '' );
	}

	/**
	 * Paid credit packs (once per order line).
	 *
	 * @param int $order_id Order.
	 */
	public static function topup_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_customer_id() ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$n = (int) get_post_meta( $item->get_product_id(), self::TOPUP, true );
			if ( $n > 0 && ! $item->get_meta( '_rinnovomem_credited' ) ) {
				$item->update_meta_data( '_rinnovomem_credited', $n * $item->get_quantity() );
				$item->save();
				self::row( $order->get_customer_id(), 'bought', 0, $n * $item->get_quantity(), 'topup', $item->get_id() );
				RINNOVOMEM_Ledger::add( $order->get_customer_id(), 'credits', (string) ( $n * $item->get_quantity() ), 'credits', $order->get_id(), time(), 0, $item->get_name() );
			}
		}
	}

	/**
	 * Refunded credit packs: the credits not used yet are taken back.
	 *
	 * @param int $order_id Order.
	 */
	public static function topup_refunded( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_customer_id() ) {
			return;
		}
		foreach ( $order->get_items() as $item ) {
			$n = (int) $item->get_meta( '_rinnovomem_credited' );
			if ( $n > 0 && ! $item->get_meta( '_rinnovomem_uncredited' ) ) {
				$back = min( $n, max( 0, self::sum( $order->get_customer_id(), 'bought' ) ) );
				$item->update_meta_data( '_rinnovomem_uncredited', $back );
				$item->save();
				if ( $back ) {
					self::row( $order->get_customer_id(), 'bought', 0, -$back, 'adjust', $item->get_id() );
				}
			}
		}
	}

	// ---------------------------------------------------------------- Front end.

	/**
	 * File details of a resource.
	 *
	 * @param int $id Resource.
	 * @return array type, size.
	 */
	public static function file_info( $id ) {
		$file = (int) get_post_meta( $id, '_rinnovomem_file', true );
		$path = $file ? get_attached_file( $file, true ) : '';
		return array(
			'type' => $path ? strtoupper( pathinfo( $path, PATHINFO_EXTENSION ) ) : '',
			'size' => $path && file_exists( $path ) ? size_format( filesize( $path ), 1 ) : '',
		);
	}

	/**
	 * Download button and eligibility for a resource.
	 *
	 * @param int $id Resource.
	 * @return string
	 */
	public static function button( $id ) {
		$user  = get_current_user_id();
		$check = self::can_download( $user, $id );
		if ( $check['ok'] ) {
			/* translators: %d: credits. */
			$label = $check['free'] ? __( 'Download again (free)', self::TD ) : ( $check['cost'] ? sprintf( _n( 'Download · %d credit', 'Download · %d credits', $check['cost'], self::TD ), $check['cost'] ) : __( 'Download', self::TD ) );
			return '<a class="button alt rinnovomem-res__dl" href="' . esc_url( self::url( $id ) ) . '" rel="nofollow">' . esc_html( $label ) . '</a>';
		}
		$join = RINNOVOMEM_Settings::get( 'join_page' ) ? get_permalink( (int) RINNOVOMEM_Settings::get( 'join_page' ) ) : wc_get_page_permalink( 'shop' );
		return '<p class="rinnovomem-res__why">' . esc_html( $check['reason'] ) . '</p>' . ( $user ? '<a class="button" href="' . esc_url( $join ) . '">' . esc_html__( 'See plans', self::TD ) . '</a>' : '<a class="button" href="' . esc_url( add_query_arg( 'redirect_to', rawurlencode( (string) get_permalink( $id ) ), wc_get_page_permalink( 'myaccount' ) ) ) . '">' . esc_html__( 'Log in', self::TD ) . '</a>' );
	}

	/**
	 * Card of a resource.
	 *
	 * @param WP_Post $post Resource.
	 * @return string
	 */
	public static function card( $post ) {
		$info = self::file_info( $post->ID );
		$cats = wp_get_post_terms( $post->ID, self::TAX, array( 'fields' => 'names' ) );
		$cost = self::cost( $post->ID );
		$html = '<article class="rinnovomem-res"><a class="rinnovomem-res__img" href="' . esc_url( get_permalink( $post ) ) . '">' . ( has_post_thumbnail( $post ) ? get_the_post_thumbnail( $post, 'medium' ) : '<span class="rinnovomem-res__type">' . esc_html( $info['type'] ) . '</span>' ) . '</a>';
		$html .= '<h3 class="rinnovomem-res__title"><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a></h3>';
		/* translators: %d: credits. */
		$html .= '<p class="rinnovomem-res__meta">' . esc_html( implode( ' · ', array_filter( array( $info['type'], $info['size'], is_wp_error( $cats ) ? '' : implode( ', ', $cats ), $cost ? sprintf( _n( '%d credit', '%d credits', $cost, self::TD ), $cost ) : __( 'Free', self::TD ) ) ) ) ) . '</p>';
		return $html . self::button( $post->ID ) . '</article>';
	}

	/**
	 * [rinnovo_resources category="photos" per_page="24"]: the resource library.
	 *
	 * @param array $atts Attributes.
	 * @return string
	 */
	public static function library( $atts ) {
		$atts = shortcode_atts(
			array(
				'category' => '',
				'per_page' => 24,
				'columns'  => 4,
			),
			$atts,
			'rinnovo_resources'
		);
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- Filters of a public list.
		$cat  = isset( $_GET['rcat'] ) ? sanitize_title( wp_unslash( $_GET['rcat'] ) ) : sanitize_title( $atts['category'] );
		$q    = isset( $_GET['rq'] ) ? sanitize_text_field( wp_unslash( $_GET['rq'] ) ) : '';
		$page = isset( $_GET['rpage'] ) ? max( 1, absint( $_GET['rpage'] ) ) : 1;
		// phpcs:enable
		$args = array(
			'post_type'      => self::TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => max( 1, min( 100, (int) $atts['per_page'] ) ),
			'paged'          => $page,
			's'              => $q,
		);
		if ( '' !== $cat ) {
			$args['tax_query'] = array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_tax_query
				array(
					'taxonomy' => self::TAX,
					'field'    => 'slug',
					'terms'    => $cat,
				),
			);
		}
		$query = new WP_Query( $args );
		wp_enqueue_style( 'rinnovomem' );
		$html  = '<div class="rinnovomem-library" style="--cols:' . (int) $atts['columns'] . '">';
		if ( is_user_logged_in() ) {
			$html .= self::balance_shortcode();
		}
		$html .= '<form class="rinnovomem-library__filter" method="get"><input type="search" name="rq" value="' . esc_attr( $q ) . '" placeholder="' . esc_attr__( 'Search resources…', self::TD ) . '" /> <select name="rcat"><option value="">' . esc_html__( 'All categories', self::TD ) . '</option>';
		foreach ( get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => true ) ) as $term ) {
			$html .= '<option value="' . esc_attr( $term->slug ) . '"' . selected( $cat, $term->slug, false ) . '>' . esc_html( $term->name ) . '</option>';
		}
		$html .= '</select> <button class="button">' . esc_html__( 'Filter', self::TD ) . '</button></form><div class="rinnovomem-library__grid">';
		foreach ( $query->posts as $post ) {
			$html .= self::card( $post );
		}
		if ( ! $query->posts ) {
			$html .= '<p>' . esc_html__( 'No resources found.', self::TD ) . '</p>';
		}
		$html .= '</div>';
		if ( $query->max_num_pages > 1 ) {
			$html .= '<p class="rinnovomem-library__pages">';
			for ( $i = 1; $i <= $query->max_num_pages; $i++ ) {
				$html .= $i === $page ? ' <strong>' . (int) $i . '</strong>' : ' <a href="' . esc_url( add_query_arg( 'rpage', $i ) ) . '">' . (int) $i . '</a>';
			}
			$html .= '</p>';
		}
		return $html . '</div>';
	}

	/**
	 * [rinnovo_credits]: credits left.
	 *
	 * @return string
	 */
	public static function balance_shortcode() {
		$user = get_current_user_id();
		if ( ! $user ) {
			return '';
		}
		$b = self::balance( $user );
		if ( ! $b['plan'] && $b['bought'] <= 0 ) {
			return '';
		}
		if ( $b['unlimited'] ) {
			$text = __( 'Unlimited downloads', self::TD );
		} else {
			$parts = array();
			foreach ( $b['plan_credits'] as $cat => $n ) {
				$term    = $cat ? get_term( $cat ) : null;
				/* translators: 1: credits left, 2: allowance. */
				$parts[] = ( $term && ! is_wp_error( $term ) ? $term->name . ': ' : '' ) . ( $cat ? (int) $n : sprintf( __( '%1$d of %2$d plan credits', self::TD ), max( 0, $n ), $b['allowance'] ) );
			}
			$text = implode( ' · ', $parts );
		}
		if ( $b['bought'] > 0 ) {
			/* translators: %d: credits. */
			$text .= ( '' !== $text ? ' + ' : '' ) . sprintf( __( '%d bought credits', self::TD ), $b['bought'] );
		}
		if ( $b['resets'] && $b['plan'] ) {
			/* translators: %s: date. */
			$text .= ' · ' . sprintf( __( 'resets %s', self::TD ), wp_date( get_option( 'date_format' ), $b['resets'] ) );
		}
		return '<p class="rinnovomem-credits">' . esc_html( $text ) . '</p>';
	}

	/**
	 * Resource page: preview, details and the button (the file itself is never linked).
	 *
	 * @param string $content Content.
	 * @return string
	 */
	public static function single( $content ) {
		if ( ! is_singular( self::TYPE ) || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}
		$id   = get_the_ID();
		$info = self::file_info( $id );
		$cost = self::cost( $id );
		/* translators: %d: credits. */
		return $content . '<div class="rinnovomem-res-single"><p>' . esc_html( implode( ' · ', array_filter( array( $info['type'], $info['size'], $cost ? sprintf( _n( '%d credit', '%d credits', $cost, self::TD ), $cost ) : __( 'Free', self::TD ) ) ) ) ) . '</p>' . self::balance_shortcode() . self::button( $id ) . '</div>';
	}

	// ---------------------------------------------------------------- Admin.

	/**
	 * Media picker script on resources.
	 *
	 * @param string $hook Screen.
	 */
	public static function admin_assets( $hook ) {
		if ( in_array( $hook, array( 'post.php', 'post-new.php' ), true ) && self::TYPE === get_post_type() ) {
			wp_enqueue_media();
		}
	}

	/**
	 * File and credits box.
	 */
	public static function meta_box() {
		add_meta_box( 'rinnovomem-res', __( 'Download file and credits', self::TD ), array( __CLASS__, 'box' ), self::TYPE, 'side', 'high' );
	}

	/**
	 * The box.
	 *
	 * @param WP_Post $post Resource.
	 */
	public static function box( $post ) {
		wp_nonce_field( 'rinnovomem_res', 'rinnovomem_res_nonce' );
		$file = (int) get_post_meta( $post->ID, '_rinnovomem_file', true );
		$info = self::file_info( $post->ID );
		echo '<p><input type="hidden" id="rinnovomem_file" name="rinnovomem_file" value="' . (int) $file . '" /><span id="rinnovomem_file_name">' . esc_html( $file ? basename( (string) get_attached_file( $file ) ) . ' (' . $info['size'] . ')' : __( 'No file yet', self::TD ) ) . '</span><br /><button type="button" class="button" id="rinnovomem_file_pick">' . esc_html__( 'Choose file', self::TD ) . '</button></p>';
		echo '<p><label>' . esc_html__( 'Credits', self::TD ) . ' <input type="number" min="0" style="width:70px" name="rinnovomem_cost" value="' . esc_attr( (string) get_post_meta( $post->ID, '_rinnovomem_cost', true ) ) . '" placeholder="' . esc_attr( (string) self::cost( $post->ID ) ) . '" /></label><br /><small>' . esc_html__( 'Empty = the category\'s cost, or 1. Premium files can cost more (e.g. PSD 3, video template 5).', self::TD ) . '</small></p>';
		echo '<p class="description">' . esc_html__( 'The file is moved to the protected folder and only downloadable through the credit check. The featured image is the public preview.', self::TD ) . '</p>';
		echo "<script>jQuery(function($){var f;$('#rinnovomem_file_pick').on('click',function(e){e.preventDefault();f=f||wp.media({title:" . wp_json_encode( __( 'Choose the download file', self::TD ) ) . ",multiple:false});f.off('select').on('select',function(){var a=f.state().get('selection').first().toJSON();$('#rinnovomem_file').val(a.id);$('#rinnovomem_file_name').text(a.filename);});f.open();});});</script>";
	}

	/**
	 * Save a resource: protect its file.
	 *
	 * @param int     $post_id Post.
	 * @param WP_Post $post    Post.
	 */
	public static function save_resource( $post_id, $post ) {
		if ( ! isset( $_POST['rinnovomem_res_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovomem_res_nonce'] ) ), 'rinnovomem_res' ) || ! current_user_can( 'edit_post', $post_id ) || wp_is_post_revision( $post_id ) ) {
			return;
		}
		$file = isset( $_POST['rinnovomem_file'] ) ? absint( $_POST['rinnovomem_file'] ) : 0;
		$cost = isset( $_POST['rinnovomem_cost'] ) ? sanitize_text_field( wp_unslash( $_POST['rinnovomem_cost'] ) ) : '';
		update_post_meta( $post_id, '_rinnovomem_cost', '' === $cost ? '' : absint( $cost ) );
		if ( $file && 'attachment' === get_post_type( $file ) ) {
			update_post_meta( $post_id, '_rinnovomem_file', $file );
			update_post_meta( $file, '_rinnovomem_resource', $post_id );
			if ( ! RINNOVOMEM_Files::is_protected( $file ) ) {
				update_post_meta( $file, RINNOVOMEM_Rules::TIER, RINNOVOMEM_Tiers::FREE );
				RINNOVOMEM_Files::move( $file, true );
			}
		}
	}

	/**
	 * Tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs['downloads'] = __( 'Download plans', self::TD );
		return $tabs;
	}

	/**
	 * One plan row.
	 *
	 * @param int|string $i    Index.
	 * @param array      $plan Plan.
	 * @param array      $cats Categories.
	 * @return string
	 */
	private static function plan_row( $i, $plan, $cats ) {
		$n    = 'plans[' . $i . ']';
		$html = '<div class="rinnovomem-tier"><div class="rinnovomem-tier__head"><input type="hidden" name="' . esc_attr( $n ) . '[id]" value="' . esc_attr( $plan['id'] ) . '" /><input type="text" class="regular-text" name="' . esc_attr( $n ) . '[name]" value="' . esc_attr( $plan['name'] ) . '" placeholder="' . esc_attr__( 'Plan name, e.g. Silver', self::TD ) . '" /> <button type="button" class="button-link-delete" onclick="this.closest(\'.rinnovomem-tier\').remove()">' . esc_html__( 'Remove', self::TD ) . '</button></div><table class="form-table" role="presentation">';
		$html .= '<tr><th>' . esc_html__( 'Given by', self::TD ) . '</th><td><select class="wc-product-search" multiple="multiple" style="width:100%" name="' . esc_attr( $n ) . '[products][]" data-action="woocommerce_json_search_products_and_variations" data-placeholder="' . esc_attr__( 'Subscription products…', self::TD ) . '">';
		foreach ( (array) $plan['products'] as $pid ) {
			$p = wc_get_product( $pid );
			if ( $p ) {
				$html .= '<option value="' . (int) $pid . '" selected="selected">' . esc_html( wp_strip_all_tags( $p->get_formatted_name() ) ) . '</option>';
			}
		}
		$html .= '</select><p>' . esc_html__( 'or members of these tiers:', self::TD ) . ' ';
		foreach ( RINNOVOMEM_Tiers::all() as $id => $tier ) {
			$html .= '<label style="margin-right:10px"><input type="checkbox" name="' . esc_attr( $n ) . '[tiers][]" value="' . esc_attr( $id ) . '"' . checked( in_array( $id, (array) $plan['tiers'], true ), true, false ) . ' /> ' . esc_html( $tier['name'] ) . '</label>';
		}
		$html .= '</p></td></tr>';
		$html .= '<tr><th>' . esc_html__( 'Credits', self::TD ) . '</th><td><input type="number" min="0" style="width:90px" name="' . esc_attr( $n ) . '[allowance]" value="' . (int) $plan['allowance'] . '" /> <select name="' . esc_attr( $n ) . '[period]">';
		foreach ( array(
			'month' => __( 'per month', self::TD ),
			'week'  => __( 'per week', self::TD ),
			'year'  => __( 'per year', self::TD ),
			'cycle' => __( 'per billing period of the subscription', self::TD ),
		) as $k => $label ) {
			$html .= '<option value="' . esc_attr( $k ) . '"' . selected( $plan['period'], $k, false ) . '>' . esc_html( $label ) . '</option>';
		}
		$html .= '</select> <span class="description">' . esc_html__( '0 = unlimited (fair use: at most 50 a day unless set below).', self::TD ) . '</span></td></tr>';
		$html .= '<tr><th>' . esc_html__( 'Unused credits', self::TD ) . '</th><td><label><input type="checkbox" name="' . esc_attr( $n ) . '[carry]" value="1"' . checked( ! empty( $plan['carry'] ), true, false ) . ' /> ' . esc_html__( 'Carry over to the next period, up to', self::TD ) . '</label> <input type="number" min="0" style="width:80px" name="' . esc_attr( $n ) . '[carry_max]" value="' . (int) $plan['carry_max'] . '" /> <span class="description">' . esc_html__( '(0 = no cap)', self::TD ) . '</span></td></tr>';
		$html .= '<tr><th>' . esc_html__( 'Limits', self::TD ) . '</th><td>' . esc_html__( 'At most', self::TD ) . ' <input type="number" min="0" style="width:80px" name="' . esc_attr( $n ) . '[per_day]" value="' . (int) $plan['per_day'] . '" /> ' . esc_html__( 'downloads a day (0 = no limit) · file types', self::TD ) . ' <input type="text" name="' . esc_attr( $n ) . '[types]" value="' . esc_attr( $plan['types'] ) . '" placeholder="jpg, png, psd, mp4" /> <span class="description">' . esc_html__( '(empty = all)', self::TD ) . '</span></td></tr>';
		if ( $cats ) {
			$html .= '<tr><th>' . esc_html__( 'Quota per category', self::TD ) . '</th><td>';
			foreach ( $cats as $tid => $name ) {
				$html .= '<label style="display:inline-block;margin:0 14px 6px 0">' . esc_html( $name ) . ' <input type="number" min="0" style="width:70px" name="' . esc_attr( $n ) . '[quotas][' . (int) $tid . ']" value="' . esc_attr( isset( $plan['quotas'][ $tid ] ) ? (string) $plan['quotas'][ $tid ] : '' ) . '" /></label>';
			}
			$html .= '<p class="description">' . esc_html__( 'Used when "Quotas per category" is chosen above (e.g. photos 30, PSD 10, videos 5).', self::TD ) . '</p></td></tr>';
		}
		return $html . '</table></div>';
	}

	/**
	 * Tab content.
	 */
	public static function render() {
		$cats = array();
		foreach ( get_terms( array( 'taxonomy' => self::TAX, 'hide_empty' => false ) ) as $t ) {
			$cats[ $t->term_id ] = $t->name;
		}
		$s = RINNOVOMEM_Settings::all();
		echo '<p>' . esc_html__( 'A resource library like a stock site: add files under Resources (with a preview image and a credit cost), put [rinnovo_resources] on a page, and give members download credits with these plans. Sell credit packs as normal products ("Download credits" in the product\'s General tab): bought credits are kept apart and never expire with the reset.', self::TD ) . '</p>';
		echo '<table class="form-table" role="presentation"><tr><th>' . esc_html__( 'Credits work as', self::TD ) . '</th><td><label><input type="radio" name="credit_mode" value="wallet"' . checked( $s['credit_mode'], 'wallet', false ) . ' /> ' . esc_html__( 'One shared wallet', self::TD ) . '</label><br /><label><input type="radio" name="credit_mode" value="category"' . checked( $s['credit_mode'], 'category', false ) . ' /> ' . esc_html__( 'Quotas per category', self::TD ) . '</label></td></tr>';
		echo '<tr><th>' . esc_html__( 'Downloading again', self::TD ) . '</th><td>' . esc_html__( 'Free within', self::TD ) . ' <input type="number" min="0" style="width:70px" name="redownload_days" value="' . (int) $s['redownload_days'] . '" /> ' . esc_html__( 'days of the first download', self::TD ) . '</td></tr>';
		echo '<tr><th>' . esc_html__( 'Failed downloads', self::TD ) . '</th><td><label><input type="checkbox" name="refund_failed" value="1"' . checked( ! empty( $s['refund_failed'] ), true, false ) . ' /> ' . esc_html__( 'Give the credits back when a download fails or is interrupted', self::TD ) . '</label></td></tr></table>';
		echo '<h2>' . esc_html__( 'Plans', self::TD ) . '</h2><div class="rinnovomem-tiers">';
		$i = 0;
		foreach ( self::plans() as $plan ) {
			echo self::plan_row( $i++, $plan, $cats ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		}
		echo self::plan_row( $i, array( 'id' => '', 'name' => '', 'products' => array(), 'tiers' => array(), 'allowance' => 10, 'period' => 'month', 'carry' => 0, 'carry_max' => 0, 'per_day' => 0, 'quotas' => array(), 'types' => '' ), $cats ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
		echo '</div><p class="description">' . esc_html__( 'Fill in the empty plan to add one; save to get another.', self::TD ) . '</p>';
		submit_button();
	}

	/**
	 * Save.
	 */
	public static function save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Checked by RINNOVOMEM_Admin::save(); cleaned below.
		$plans = array();
		foreach ( isset( $_POST['plans'] ) ? (array) wp_unslash( $_POST['plans'] ) : array() as $raw ) {
			$name = isset( $raw['name'] ) ? sanitize_text_field( $raw['name'] ) : '';
			if ( '' === $name ) {
				continue;
			}
			$quotas = array();
			foreach ( isset( $raw['quotas'] ) ? (array) $raw['quotas'] : array() as $tid => $q ) {
				if ( '' !== (string) $q ) {
					$quotas[ absint( $tid ) ] = absint( $q );
				}
			}
			$plans[] = array(
				'id'        => ! empty( $raw['id'] ) ? sanitize_key( $raw['id'] ) : sanitize_key( sanitize_title( $name ) ) . '-' . wp_rand( 10, 99 ),
				'name'      => $name,
				'products'  => isset( $raw['products'] ) ? array_values( array_filter( array_map( 'absint', (array) $raw['products'] ) ) ) : array(),
				'tiers'     => isset( $raw['tiers'] ) ? array_values( array_intersect( array_map( 'sanitize_key', (array) $raw['tiers'] ), array_keys( RINNOVOMEM_Tiers::all() ) ) ) : array(),
				'allowance' => isset( $raw['allowance'] ) ? absint( $raw['allowance'] ) : 0,
				'period'    => isset( $raw['period'] ) && in_array( $raw['period'], array( 'month', 'week', 'year', 'cycle' ), true ) ? $raw['period'] : 'month',
				'carry'     => ! empty( $raw['carry'] ) ? 1 : 0,
				'carry_max' => isset( $raw['carry_max'] ) ? absint( $raw['carry_max'] ) : 0,
				'per_day'   => isset( $raw['per_day'] ) ? absint( $raw['per_day'] ) : 0,
				'quotas'    => $quotas,
				'types'     => isset( $raw['types'] ) ? sanitize_text_field( $raw['types'] ) : '',
			);
		}
		update_option( self::PLANS, $plans, false );
		RINNOVOMEM_Settings::save(
			array(
				'credit_mode'     => isset( $_POST['credit_mode'] ) && 'category' === $_POST['credit_mode'] ? 'category' : 'wallet',
				'redownload_days' => isset( $_POST['redownload_days'] ) ? min( 365, absint( $_POST['redownload_days'] ) ) : 30,
				'refund_failed'   => ! empty( $_POST['refund_failed'] ) ? 1 : 0,
			)
		);
		// phpcs:enable
		set_transient( 'rinnovomem_tab_note_' . get_current_user_id(), array( 'success', __( 'Download plans saved.', self::TD ) ), 60 );
	}
}
