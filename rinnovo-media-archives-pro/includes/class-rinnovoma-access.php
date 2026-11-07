<?php
/**
 * Access: a user's plan worked out live from their Rinnovo subscriptions (a failed payment keeps access for
 * the grace period, a paused subscription has none), status events for the webhooks, and the instant
 * cut-off: app tokens, streams and devices are revoked the moment access ends. Also usage quotas per
 * billing cycle, device and stream limits, trials without a card and upgrades with credit.
 *
 * @package RinnovoMedia
 */

defined( 'ABSPATH' ) || exit;

/**
 * Access.
 */
class RINNOVOMA_Access {

	const TD      = 'rinnovo-media-archives-pro';
	const SESSION = 'rinnovoma_switch';

	/**
	 * Per request cache.
	 *
	 * @var array
	 */
	private static $cache = array();

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'rinnovo_subscription_created', array( __CLASS__, 'on_created' ), 20, 2 );
		add_action( 'rinnovo_subscription_status_changed', array( __CLASS__, 'on_status' ), 20, 2 );
		add_action( 'rinnovoma_hourly', array( __CLASS__, 'hourly' ) );
		add_action( 'wp_login', array( __CLASS__, 'limit_logins' ), 20, 2 );
		add_action(
			'init',
			static function () {
				if ( ! wp_next_scheduled( 'rinnovoma_hourly' ) ) {
					wp_schedule_event( time() + 300, 'hourly', 'rinnovoma_hourly' );
				}
			}
		);

		// Trials without a card, and upgrades with credit.
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_field' ), 45 );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_field' ), 45 );
		add_filter( 'woocommerce_cart_needs_payment', array( __CLASS__, 'trial_needs_payment' ), 25, 2 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'auto_switch' ), 20, 3 );
		add_action( 'woocommerce_cart_calculate_fees', array( __CLASS__, 'credit_fee' ), 30 );
		add_action( 'woocommerce_checkout_create_order', array( __CLASS__, 'mark_switch' ), 20 );
		add_action( 'woocommerce_store_api_checkout_update_order_from_request', array( __CLASS__, 'mark_switch' ), 20 );
		add_action( 'rinnovo_subscription_created', array( __CLASS__, 'finish_switch' ), 5, 2 );
	}

	/**
	 * Forget cached state.
	 *
	 * @param int $user_id User.
	 */
	public static function flush( $user_id ) {
		unset( self::$cache[ (int) $user_id ] );
	}

	/**
	 * A subscription's pause: from the end of the paid period until the chosen date.
	 *
	 * @param object $sub Subscription.
	 * @return array|null from, until.
	 */
	public static function pause_of( $sub ) {
		$all = (array) get_user_meta( (int) $sub->user_id, '_rinnovoma_paused', true );
		if ( empty( $all[ $sub->id ] ) || ! is_array( $all[ $sub->id ] ) || (int) $all[ $sub->id ]['until'] <= time() ) {
			return null;
		}
		return array(
			'from'  => (int) $all[ $sub->id ]['from'],
			'until' => (int) $all[ $sub->id ]['until'],
		);
	}

	/**
	 * Whether a subscription is paused right now (no access, no billing).
	 *
	 * @param object $sub Subscription.
	 * @return bool
	 */
	public static function is_paused( $sub ) {
		$pause = self::pause_of( $sub );
		return $pause && $pause['from'] <= time();
	}

	/**
	 * Pause a subscription for some months, starting when the paid period ends.
	 *
	 * @param object $sub    Subscription.
	 * @param int    $months Months.
	 * @return true|WP_Error
	 */
	public static function pause( $sub, $months ) {
		$max = max( 1, (int) RINNOVOMA_Plans::get( 'pause_months' ) );
		if ( $months < 1 || $months > $max || ! in_array( $sub->status, array( 'active', 'trial' ), true ) || ! $sub->next_payment ) {
			return new WP_Error( 'rinnovoma_pause', __( 'This subscription cannot be paused.', self::TD ) );
		}
		$from  = strtotime( $sub->next_payment . ' UTC' );
		$until = ( new DateTimeImmutable( '@' . $from ) )->modify( '+' . (int) $months . ' month' )->getTimestamp();
		if ( RINNOVO_Manager::is_automatic( $sub ) ) {
			$r = RINNOVO_Stripe_API::request(
				'POST',
				'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ),
				array(
					'pause_collection' => array(
						'behavior'   => 'void',
						'resumes_at' => $until,
					),
				)
			);
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		} else {
			RINNOVO_Subscriptions::update( $sub->id, array( 'next_payment' => RINNOVO_Subscriptions::mysql( $until ) ) );
		}
		$all             = (array) get_user_meta( (int) $sub->user_id, '_rinnovoma_paused', true );
		$all[ $sub->id ] = array(
			'from'  => $from,
			'until' => $until,
		);
		update_user_meta( (int) $sub->user_id, '_rinnovoma_paused', $all );
		self::flush( $sub->user_id );
		RINNOVOMA_Api::event( 'subscription.paused', (int) $sub->user_id, $sub, array( 'paused_from' => gmdate( 'c', $from ), 'paused_until' => gmdate( 'c', $until ) ) );
		return true;
	}

	/**
	 * End a pause now.
	 *
	 * @param object $sub Subscription.
	 * @return true|WP_Error
	 */
	public static function resume( $sub ) {
		$pause = self::pause_of( $sub );
		if ( ! $pause ) {
			return new WP_Error( 'rinnovoma_pause', __( 'This subscription is not paused.', self::TD ) );
		}
		if ( RINNOVO_Manager::is_automatic( $sub ) ) {
			$r = RINNOVO_Stripe_API::request( 'POST', 'subscriptions/' . rawurlencode( $sub->gateway_subscription_id ), array( 'pause_collection' => '' ) );
			if ( is_wp_error( $r ) ) {
				return $r;
			}
		} else {
			RINNOVO_Subscriptions::update( $sub->id, array( 'next_payment' => RINNOVO_Subscriptions::mysql( max( time() + 60, $pause['from'] ) ) ) );
		}
		$all = (array) get_user_meta( (int) $sub->user_id, '_rinnovoma_paused', true );
		unset( $all[ $sub->id ] );
		update_user_meta( (int) $sub->user_id, '_rinnovoma_paused', $all );
		self::flush( $sub->user_id );
		RINNOVOMA_Api::event( 'subscription.active', (int) $sub->user_id, $sub );
		return true;
	}

	/**
	 * Whether a subscription gives access now.
	 *
	 * @param object $sub Subscription.
	 * @return bool
	 */
	public static function sub_live( $sub ) {
		if ( self::is_paused( $sub ) ) {
			return false;
		}
		if ( in_array( $sub->status, array( 'trial', 'active', 'pending-cancel' ), true ) ) {
			return true;
		}
		if ( 'on-hold' !== $sub->status ) {
			return false;
		}
		$holds = (array) get_user_meta( (int) $sub->user_id, '_rinnovoma_hold', true );
		$since = isset( $holds[ $sub->id ] ) ? (int) $holds[ $sub->id ] : time();
		return time() - $since <= max( 0, (int) RINNOVOMA_Plans::get( 'grace_days' ) ) * DAY_IN_SECONDS;
	}

	/**
	 * A user's access now.
	 *
	 * @param int $user_id User.
	 * @return array active, plan, plan_name, sub (object|null), status, trial, paused_until, features, renews.
	 */
	public static function state( $user_id ) {
		$user_id = (int) $user_id;
		if ( isset( self::$cache[ $user_id ] ) ) {
			return self::$cache[ $user_id ];
		}
		$state = array(
			'active'       => false,
			'plan'         => '',
			'plan_name'    => '',
			'sub'          => null,
			'status'       => 'none',
			'trial'        => false,
			'paused_until' => 0,
			'features'     => array(),
			'renews'       => 0,
		);
		$best  = -1;
		foreach ( $user_id ? RINNOVO_Subscriptions::for_user( $user_id ) : array() as $sub ) {
			$plan = RINNOVOMA_Plans::for_product( $sub->product_id );
			if ( '' === $plan ) {
				continue;
			}
			$pause = self::pause_of( $sub );
			if ( $pause && ! $state['active'] ) {
				$state['paused_until'] = $pause['until'];
				$state['sub']          = $sub;
				$state['status']       = 'paused';
			}
			$rank = RINNOVOMA_Plans::rank( $plan );
			if ( ! self::sub_live( $sub ) || $rank <= $best ) {
				continue;
			}
			$best = $rank;
			$p    = RINNOVOMA_Plans::get_plan( $plan );
			$state = array(
				'active'       => true,
				'plan'         => $plan,
				'plan_name'    => $p['name'],
				'sub'          => $sub,
				'status'       => $sub->status,
				'trial'        => 'trial' === $sub->status,
				'paused_until' => 0,
				'features'     => $p['flags'],
				'renews'       => $sub->next_payment ? strtotime( $sub->next_payment . ' UTC' ) : 0,
			);
		}
		/**
		 * A user's access.
		 *
		 * @param array $state   Access.
		 * @param int   $user_id User.
		 */
		self::$cache[ $user_id ] = (array) apply_filters( 'rinnovoma_access', $state, $user_id );
		return self::$cache[ $user_id ];
	}

	/**
	 * Whether a user has a plan (or higher).
	 *
	 * @param int    $user_id User.
	 * @param string $plan    Plan ID or 'any'.
	 * @return bool
	 */
	public static function has( $user_id, $plan = 'any' ) {
		if ( $user_id && ( user_can( $user_id, 'manage_woocommerce' ) || user_can( $user_id, 'edit_others_posts' ) ) ) {
			return true;
		}
		$state = self::state( $user_id );
		return $state['active'] && RINNOVOMA_Plans::rank( $state['plan'] ) >= RINNOVOMA_Plans::rank( $plan );
	}

	/**
	 * Data the API, webhooks and tokens share.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function payload( $user_id ) {
		$user  = get_userdata( (int) $user_id );
		$state = self::state( $user_id );
		$sub   = $state['sub'];
		return array(
			'user'         => array(
				'id'    => (int) $user_id,
				'email' => $user ? $user->user_email : '',
				'name'  => $user ? $user->display_name : '',
			),
			'active'       => $state['active'],
			'status'       => $state['status'],
			'plan'         => $state['plan'],
			'plan_name'    => $state['plan_name'],
			'trial'        => $state['trial'],
			'features'     => (object) $state['features'],
			'usage'        => (object) self::usage_report( $user_id ),
			'renews_at'    => $state['renews'] ? gmdate( 'c', $state['renews'] ) : null,
			'paused_until' => $state['paused_until'] ? gmdate( 'c', $state['paused_until'] ) : null,
			'subscription' => $sub ? array(
				'id'       => (int) $sub->id,
				'status'   => $sub->status,
				'product'  => (int) $sub->product_id,
				'amount'   => (float) $sub->recurring_amount,
				'currency' => $sub->currency,
				'period'   => $sub->period,
				'interval' => (int) $sub->interval_count,
			) : null,
		);
	}

	// ---------------------------------------------------------------- Events and cut-off.

	/**
	 * New subscription.
	 *
	 * @param object $sub Subscription.
	 */
	public static function on_created( $sub ) {
		self::flush( $sub->user_id );
		if ( '' !== RINNOVOMA_Plans::for_product( $sub->product_id ) ) {
			RINNOVOMA_Api::event( 'trial' === $sub->status ? 'subscription.trial' : 'subscription.active', (int) $sub->user_id, $sub );
		}
	}

	/**
	 * Status change: events, grace bookkeeping, and the cut-off when access ends.
	 *
	 * @param object $sub Subscription.
	 * @param string $old Old status.
	 */
	public static function on_status( $sub, $old ) {
		if ( '' === RINNOVOMA_Plans::for_product( $sub->product_id ) ) {
			return;
		}
		$user  = (int) $sub->user_id;
		$holds = (array) get_user_meta( $user, '_rinnovoma_hold', true );
		if ( 'on-hold' === $sub->status ) {
			$holds[ $sub->id ] = isset( $holds[ $sub->id ] ) ? $holds[ $sub->id ] : time();
		} else {
			unset( $holds[ $sub->id ] );
		}
		update_user_meta( $user, '_rinnovoma_hold', array_filter( $holds ) );
		self::flush( $user );

		$map = array(
			'active'         => 'subscription.active',
			'trial'          => 'subscription.trial',
			'pending-cancel' => 'subscription.cancelled',
			'cancelled'      => 'subscription.cancelled',
			'expired'        => 'subscription.expired',
			'on-hold'        => 'subscription.payment_failed',
		);
		if ( isset( $map[ $sub->status ] ) && ! ( 'cancelled' === $sub->status && 'pending-cancel' === $old ) ) {
			RINNOVOMA_Api::event( $map[ $sub->status ], $user, $sub );
		}
		if ( 'cancelled' === $sub->status && 'pending-cancel' === $old ) {
			RINNOVOMA_Api::event( 'subscription.expired', $user, $sub );
		}
		if ( ! self::state( $user )['active'] ) {
			self::revoke( $user, $sub->status );
		}
	}

	/**
	 * End everything at once: app tokens, refresh tokens, streams and app devices (and optionally site log-ins).
	 *
	 * @param int    $user_id User.
	 * @param string $why     Reason.
	 */
	public static function revoke( $user_id, $why = '' ) {
		update_user_meta( $user_id, '_rinnovoma_token_version', (int) get_user_meta( $user_id, '_rinnovoma_token_version', true ) + 1 );
		delete_user_meta( $user_id, '_rinnovoma_refresh' );
		delete_user_meta( $user_id, '_rinnovoma_streams' );
		delete_user_meta( $user_id, '_rinnovoma_devices' );
		if ( RINNOVOMA_Plans::get( 'cutoff_logout' ) && class_exists( 'WP_Session_Tokens' ) ) {
			WP_Session_Tokens::get_instance( $user_id )->destroy_all();
		}
		RINNOVOMA_Api::event( 'access.revoked', $user_id, null, array( 'reason' => $why ) );
		/**
		 * A user's access ended.
		 *
		 * @param int    $user_id User.
		 * @param string $why     Reason.
		 */
		do_action( 'rinnovoma_access_revoked', $user_id, $why );
	}

	/**
	 * Hourly: grace periods and pauses that ended.
	 */
	public static function hourly() {
		$users = get_users(
			array(
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'OR',
					array(
						'key'     => '_rinnovoma_hold',
						'compare' => 'EXISTS',
					),
					array(
						'key'     => '_rinnovoma_paused',
						'compare' => 'EXISTS',
					),
				),
				'fields'     => 'ID',
				'number'     => 500,
			)
		);
		foreach ( $users as $user_id ) {
			$was = (int) get_user_meta( $user_id, '_rinnovoma_grace_revoked', true );
			self::flush( $user_id );
			if ( ! self::state( $user_id )['active'] && ! $was ) {
				self::revoke( (int) $user_id, array_filter( (array) get_user_meta( $user_id, '_rinnovoma_hold', true ) ) ? 'payment_failed' : 'paused' );
				update_user_meta( $user_id, '_rinnovoma_grace_revoked', 1 );
			} elseif ( self::state( $user_id )['active'] ) {
				delete_user_meta( $user_id, '_rinnovoma_grace_revoked' );
			}
		}
	}

	// ---------------------------------------------------------------- Usage quotas.

	/**
	 * Key of the current billing cycle (quotas reset when it changes).
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	private static function cycle( $user_id ) {
		$state = self::state( $user_id );
		return $state['sub'] && $state['sub']->next_payment ? $state['sub']->id . '@' . $state['sub']->next_payment : gmdate( 'Y-m' );
	}

	/**
	 * Used, limit and remaining of each numeric feature.
	 *
	 * @param int $user_id User.
	 * @return array<string, array>
	 */
	public static function usage_report( $user_id ) {
		$state = self::state( $user_id );
		$all   = (array) get_user_meta( (int) $user_id, '_rinnovoma_usage', true );
		$used  = isset( $all[ self::cycle( $user_id ) ] ) ? (array) $all[ self::cycle( $user_id ) ] : array();
		$out   = array();
		foreach ( $state['features'] as $key => $limit ) {
			if ( is_int( $limit ) ) {
				$u           = isset( $used[ $key ] ) ? (int) $used[ $key ] : 0;
				$out[ $key ] = array(
					'limit'     => $limit,
					'used'      => $u,
					'remaining' => max( 0, $limit - $u ),
				);
			}
		}
		return $out;
	}

	/**
	 * Record usage of a quota.
	 *
	 * @param int    $user_id User.
	 * @param string $feature Feature.
	 * @param int    $amount  Amount (negative gives back).
	 * @return array|WP_Error Usage of the feature.
	 */
	public static function use_quota( $user_id, $feature, $amount ) {
		$feature = strtolower( sanitize_key( $feature ) );
		$report  = self::usage_report( $user_id );
		if ( ! isset( $report[ $feature ] ) ) {
			return new WP_Error( 'rinnovoma_feature', __( 'This plan has no such quota.', self::TD ), array( 'status' => 403 ) );
		}
		if ( $amount > 0 && $report[ $feature ]['remaining'] < $amount ) {
			return new WP_Error( 'rinnovoma_quota', __( 'Quota used up for this billing period.', self::TD ), array( 'status' => 402 ) );
		}
		$cycle = self::cycle( $user_id );
		$all   = (array) get_user_meta( (int) $user_id, '_rinnovoma_usage', true );
		$all   = array( $cycle => isset( $all[ $cycle ] ) ? (array) $all[ $cycle ] : array() );
		$all[ $cycle ][ $feature ] = max( 0, ( isset( $all[ $cycle ][ $feature ] ) ? (int) $all[ $cycle ][ $feature ] : 0 ) + (int) $amount );
		update_user_meta( (int) $user_id, '_rinnovoma_usage', $all );
		return self::usage_report( $user_id )[ $feature ];
	}

	// ---------------------------------------------------------------- Devices and streams.

	/**
	 * Heartbeat of a device or stream: the oldest ones within the limit may continue.
	 *
	 * @param int    $user_id User.
	 * @param string $kind    streams or devices.
	 * @param string $id      Device / stream ID.
	 * @param bool   $stop    It stopped.
	 * @return array allowed (bool), active (int), limit (int).
	 */
	public static function heartbeat( $user_id, $kind, $id, $stop = false ) {
		$state = self::state( $user_id );
		$plan  = RINNOVOMA_Plans::get_plan( $state['plan'] );
		$limit = $plan ? (int) $plan[ $kind ] : 0;
		$key   = '_rinnovoma_' . $kind;
		$list  = (array) get_user_meta( $user_id, $key, true );
		$now   = time();
		foreach ( $list as $k => $row ) {
			if ( ! is_array( $row ) || $now - (int) $row['seen'] > 120 ) {
				unset( $list[ $k ] );
			}
		}
		if ( $stop ) {
			unset( $list[ $id ] );
		} else {
			if ( ! isset( $list[ $id ] ) ) {
				$list[ $id ] = array(
					'start' => $now,
					'ip'    => RINNOVOMA_Security::ip(),
				);
			}
			$list[ $id ]['seen'] = $now;
		}
		uasort(
			$list,
			static function ( $a, $b ) {
				return (int) $a['start'] - (int) $b['start'];
			}
		);
		update_user_meta( $user_id, $key, $list );
		$allowed = $stop || $limit < 1 || in_array( $id, array_slice( array_keys( $list ), 0, $limit ), true );
		return array(
			'allowed' => $state['active'] && $allowed,
			'active'  => count( $list ),
			'limit'   => $limit,
		);
	}

	/**
	 * At most N devices logged in to the site (the plan's device limit, or the store setting).
	 *
	 * @param string  $login Login.
	 * @param WP_User $user  User.
	 */
	public static function limit_logins( $login, $user ) {
		if ( ! $user instanceof WP_User || user_can( $user, 'edit_others_posts' ) ) {
			return;
		}
		$plan  = RINNOVOMA_Plans::get_plan( self::state( $user->ID )['plan'] );
		$limit = $plan && $plan['devices'] ? (int) $plan['devices'] : (int) RINNOVOMA_Plans::get( 'max_logins' );
		$all   = get_user_meta( $user->ID, 'session_tokens', true );
		if ( $limit < 1 || ! is_array( $all ) || count( $all ) <= $limit ) {
			return;
		}
		uasort(
			$all,
			static function ( $a, $b ) {
				return (int) ( isset( $b['login'] ) ? $b['login'] : 0 ) - (int) ( isset( $a['login'] ) ? $a['login'] : 0 );
			}
		);
		update_user_meta( $user->ID, 'session_tokens', array_slice( $all, 0, $limit, true ) );
	}

	// ---------------------------------------------------------------- Trials without a card.

	/**
	 * Product field.
	 */
	public static function product_field() {
		echo '<div class="options_group show_if_simple show_if_variable">';
		woocommerce_wp_checkbox(
			array(
				'id'          => '_rinnovoma_trial_no_card',
				'label'       => __( 'Trial without a card', self::TD ),
				'description' => __( 'With a free trial (General tab or variations), sign-up asks for no payment method; a "Pay now" invoice is sent when the trial ends.', self::TD ),
			)
		);
		echo '</div>';
	}

	/**
	 * Save it.
	 *
	 * @param int $product_id Product.
	 */
	public static function save_product_field( $product_id ) {
		if ( isset( $_POST['woocommerce_meta_nonce'] ) && wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) && current_user_can( 'edit_product', $product_id ) ) {
			update_post_meta( $product_id, '_rinnovoma_trial_no_card', isset( $_POST['_rinnovoma_trial_no_card'] ) ? 'yes' : 'no' );
		}
	}

	/**
	 * A free trial without a card asks for no payment method.
	 *
	 * @param bool    $needs Needs payment.
	 * @param WC_Cart $cart  Cart.
	 * @return bool
	 */
	public static function trial_needs_payment( $needs, $cart ) {
		$item = RINNOVO_Product::cart_subscription();
		if ( ! $needs || ! $item || (float) $cart->get_total( 'edit' ) > 0 ) {
			return $needs;
		}
		$plan   = RINNOVO_Product::plan( $item['data'] );
		$parent = $item['data']->get_parent_id() ? $item['data']->get_parent_id() : $item['data']->get_id();
		return $plan && $plan['trial_days'] && 'yes' === get_post_meta( $parent, '_rinnovoma_trial_no_card', true ) ? false : $needs;
	}

	// ---------------------------------------------------------------- Upgrades with credit.

	/**
	 * The user's current plan subscription.
	 *
	 * @param int $user_id User.
	 * @return object|null
	 */
	public static function current_sub( $user_id ) {
		return self::state( $user_id )['sub'];
	}

	/**
	 * Credit for the unused part of the paid period.
	 *
	 * @param object $sub Subscription.
	 * @return float
	 */
	public static function credit( $sub ) {
		if ( ! RINNOVOMA_Plans::get( 'switch_credit' ) || ! in_array( $sub->status, array( 'active', 'pending-cancel' ), true ) || ! $sub->next_payment || (int) $sub->payments_made < 1 ) {
			return 0.0;
		}
		$next = strtotime( $sub->next_payment . ' UTC' );
		if ( $next <= time() ) {
			return 0.0;
		}
		$unit = in_array( $sub->period, array( 'day', 'week', 'month', 'year' ), true ) ? $sub->period : 'month';
		$prev = ( new DateTimeImmutable( '@' . $next ) )->modify( '-' . max( 1, (int) $sub->interval_count ) . ' ' . $unit )->getTimestamp();
		return round( (float) $sub->recurring_amount * min( 1, ( $next - time() ) / max( 1, $next - $prev ) ), wc_get_price_decimals() );
	}

	/**
	 * Plans and billing periods a user can switch to.
	 *
	 * @param object $sub Current subscription.
	 * @return WC_Product[]
	 */
	public static function switch_options( $sub ) {
		$out = array();
		foreach ( RINNOVOMA_Plans::all() as $plan ) {
			foreach ( $plan['products'] as $pid ) {
				$product = wc_get_product( $pid );
				if ( ! $product ) {
					continue;
				}
				$items = $product->is_type( 'variable' ) ? array_map( 'wc_get_product', $product->get_children() ) : array( $product );
				foreach ( array_filter( $items ) as $item ) {
					if ( (int) $item->get_id() !== (int) $sub->product_id && $item->is_purchasable() && RINNOVO_Product::is_subscription( $item ) ) {
						$out[ $item->get_id() ] = $item;
					}
				}
			}
		}
		return $out;
	}

	/**
	 * Buying another plan replaces the current one.
	 *
	 * @param bool $valid      Valid.
	 * @param int  $product_id Product.
	 * @param int  $qty        Quantity.
	 * @return bool
	 */
	public static function auto_switch( $valid, $product_id, $qty ) {
		if ( ! $valid || ! is_user_logged_in() || ! WC()->session ) {
			return $valid;
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Add to cart has no nonce.
		$target = isset( $_REQUEST['variation_id'] ) && absint( $_REQUEST['variation_id'] ) ? absint( $_REQUEST['variation_id'] ) : (int) $product_id;
		$sub    = self::current_sub( get_current_user_id() );
		if ( $sub && '' !== RINNOVOMA_Plans::for_product( $target ) && (int) $sub->product_id !== $target && RINNOVO_Product::is_subscription( $target ) ) {
			WC()->session->set( self::SESSION, (int) $sub->id );
			wc_add_notice( __( 'This plan replaces your current one. The unused part of your current period is taken off the price.', self::TD ), 'notice' );
		}
		return $valid;
	}

	/**
	 * The plan change this cart is for.
	 *
	 * @return object|null
	 */
	private static function pending_switch() {
		$id   = WC()->session ? (int) WC()->session->get( self::SESSION ) : 0;
		$sub  = $id ? RINNOVO_Subscriptions::get( $id ) : null;
		$item = RINNOVO_Product::cart_subscription();
		return $sub && $item && (int) $sub->user_id === get_current_user_id() && '' !== RINNOVOMA_Plans::for_product( $item['data']->get_id() ) ? $sub : null;
	}

	/**
	 * Credit at checkout.
	 *
	 * @param WC_Cart $cart Cart.
	 */
	public static function credit_fee( $cart ) {
		$old = self::pending_switch();
		if ( ! $old ) {
			return;
		}
		$credit = min( self::credit( $old ), (float) $cart->get_subtotal() + (float) $cart->get_subtotal_tax() );
		if ( $credit > 0 ) {
			/* translators: %s: plan. */
			$cart->add_fee( sprintf( __( 'Credit for unused time on %s', self::TD ), $old->name ), -1 * $credit, false );
		}
	}

	/**
	 * Mark the order.
	 *
	 * @param WC_Order $order Order.
	 */
	public static function mark_switch( $order ) {
		$old = WC()->session ? self::pending_switch() : null;
		if ( $old ) {
			$order->update_meta_data( '_rinnovoma_switch_from', (int) $old->id );
		}
	}

	/**
	 * The new plan started: end the old subscription (without revoking access in between).
	 *
	 * @param object   $sub   New subscription.
	 * @param WC_Order $order Order.
	 */
	public static function finish_switch( $sub, $order ) {
		$old = RINNOVO_Subscriptions::get( (int) $order->get_meta( '_rinnovoma_switch_from' ) );
		if ( ! $old || (int) $old->user_id !== (int) $sub->user_id || (int) $old->id === (int) $sub->id || in_array( $old->status, array( 'cancelled', 'expired' ), true ) ) {
			return;
		}
		RINNOVO_Manager::cancel( $old, false );
		if ( WC()->session ) {
			WC()->session->set( self::SESSION, null );
		}
		/* translators: 1: old, 2: new subscription. */
		$order->add_order_note( sprintf( __( 'Plan changed: subscription #%1$d ended, #%2$d started.', self::TD ), $old->id, $sub->id ) );
	}
}
