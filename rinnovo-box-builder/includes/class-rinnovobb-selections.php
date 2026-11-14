<?php
/**
 * What each subscriber wants in their coming boxes.
 *
 * Every subscription has a "default" box (used for every delivery) and can have a box for one delivery
 * ("change my next box only"). Changes are possible until the selection deadline; after that the box is
 * locked. A customer who chose nothing gets their default box, or the merchant's default box (auto-fill).
 * The box used for a delivery is kept with its order, so a renewal that runs twice never changes it.
 *
 * @package RinnovoBoxBuilder
 */

defined( 'ABSPATH' ) || exit;

/**
 * Selections per subscription.
 */
class RINNOVOBB_Selections {

	const TD = 'rinnovo-box-builder';
	const DB = '1';

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rinnovobb_selections';
	}

	/**
	 * Hooks.
	 */
	public static function init() {
		if ( get_option( 'rinnovobb_db' ) !== self::DB ) {
			self::install();
		}
		add_action( 'rinnovo_subscription_created', array( __CLASS__, 'created' ), 8, 2 );
		add_action( 'rinnovo_hourly', array( __CLASS__, 'reminders' ), 30 );
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				subscription_id bigint(20) unsigned NOT NULL,
				cycle varchar(10) NOT NULL,
				data longtext NOT NULL,
				status varchar(10) NOT NULL DEFAULT 'open',
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				reminded varchar(10) NOT NULL DEFAULT '',
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY sub_cycle (subscription_id, cycle),
				KEY order_id (order_id)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'rinnovobb_db', self::DB, false );
	}

	/**
	 * A stored row.
	 *
	 * @param int    $sub_id Subscription.
	 * @param string $cycle  Y-m-d, or "default".
	 * @return object|null
	 */
	public static function row( $sub_id, $cycle ) {
		global $wpdb;
		return $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE subscription_id = %d AND cycle = %s', self::table(), $sub_id, $cycle ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * Selection stored for a subscription.
	 *
	 * @param int    $sub_id Subscription.
	 * @param string $cycle  Y-m-d, or "default".
	 * @return array|null
	 */
	public static function get( $sub_id, $cycle = 'default' ) {
		$row = self::row( $sub_id, $cycle );
		if ( ! $row ) {
			return null;
		}
		$data = json_decode( (string) $row->data, true );
		return is_array( $data ) ? $data : null;
	}

	/**
	 * Store a selection.
	 *
	 * @param int    $sub_id Subscription.
	 * @param string $cycle  Y-m-d, or "default".
	 * @param array  $sel    Selection (or snapshot when used).
	 * @param string $status open|used.
	 * @param int    $order  Order it was used for.
	 */
	public static function put( $sub_id, $cycle, $sel, $status = 'open', $order = 0 ) {
		global $wpdb;
		$row  = self::row( $sub_id, $cycle );
		$data = array(
			'data'       => wp_json_encode( $sel ),
			'status'     => $status,
			'order_id'   => (int) $order,
			'updated_at' => current_time( 'mysql', true ),
		);
		if ( $row ) {
			$wpdb->update( self::table(), $data, array( 'id' => $row->id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		} else {
			$wpdb->insert( self::table(), array_merge( $data, array( 'subscription_id' => (int) $sub_id, 'cycle' => $cycle ) ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		}
	}

	/**
	 * Remove a one-delivery change.
	 *
	 * @param int    $sub_id Subscription.
	 * @param string $cycle  Y-m-d.
	 */
	public static function forget( $sub_id, $cycle ) {
		global $wpdb;
		$wpdb->delete( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			self::table(),
			array(
				'subscription_id' => (int) $sub_id,
				'cycle'           => $cycle,
				'status'          => 'open',
			)
		);
	}

	/**
	 * Keep the first box as the subscription's default box.
	 *
	 * @param object   $sub   Subscription.
	 * @param WC_Order $order First order.
	 */
	public static function created( $sub, $order ) {
		$line = RINNOVO_Product::order_subscription_item( $order );
		$snap = $line ? $line->get_meta( '_rinnovobb_snapshot' ) : null;
		if ( is_array( $snap ) && isset( $snap['sel'] ) ) {
			self::put( $sub->id, 'default', $snap['sel'] );
			self::put( $sub->id, 'last', $snap );
		}
	}

	/**
	 * Delivery date of the next box (the next payment date).
	 *
	 * @param object $sub Subscription.
	 * @return int
	 */
	public static function next_time( $sub ) {
		return $sub->next_payment ? (int) strtotime( $sub->next_payment . ' UTC' ) : time();
	}

	/**
	 * Cycle key of a date.
	 *
	 * @param int $time Date.
	 * @return string
	 */
	public static function cycle( $time ) {
		return gmdate( 'Y-m-d', (int) $time );
	}

	/**
	 * Last moment the box for a delivery can be changed.
	 *
	 * @param array $config Configuration.
	 * @param int   $time   Delivery (renewal) time.
	 * @return int
	 */
	public static function deadline( $config, $time ) {
		$d = $config['deadline'];
		if ( ! $d['enabled'] ) {
			return $time;
		}
		if ( 'hours' === $d['day'] ) {
			return $time - (int) $d['hours'] * HOUR_IN_SECONDS;
		}
		// The last chosen weekday at the chosen time (site time) before the delivery.
		$tz   = wp_timezone();
		$at   = ( new DateTimeImmutable( '@' . $time ) )->setTimezone( $tz );
		list( $h, $m ) = array_map( 'intval', explode( ':', $d['time'] ) );
		$days = array_keys( RINNOVOBB_Config::days() );
		$want = array_search( $d['day'], $days, true ) + 1;
		for ( $i = 0; $i < 8; $i++ ) {
			$try = $at->modify( '-' . $i . ' days' )->setTime( $h, $m );
			if ( (int) $try->format( 'N' ) === $want && $try->getTimestamp() < $time ) {
				return $try->getTimestamp();
			}
		}
		return $time;
	}

	/**
	 * When the menu for a delivery is published (customers can choose from then on).
	 *
	 * @param array $config Configuration.
	 * @param int   $time   Delivery time.
	 * @return int
	 */
	public static function opens( $config, $time ) {
		if ( ! $config['deadline']['enabled'] ) {
			return 0;
		}
		$cut  = self::deadline( $config, $time );
		$at   = ( new DateTimeImmutable( '@' . $cut ) )->setTimezone( wp_timezone() );
		$days = array_keys( RINNOVOBB_Config::days() );
		$want = array_search( $config['deadline']['publish'], $days, true ) + 1;
		for ( $i = 0; $i < 8; $i++ ) {
			$try = $at->modify( '-' . $i . ' days' )->setTime( 0, 0 );
			if ( (int) $try->format( 'N' ) === $want ) {
				return $try->getTimestamp();
			}
		}
		return 0;
	}

	/**
	 * Whether the next box can still be changed.
	 *
	 * @param object $sub    Subscription.
	 * @param array  $config Configuration.
	 * @return bool
	 */
	public static function open( $sub, $config ) {
		$time = self::next_time( $sub );
		return time() < self::deadline( $config, $time ) && ! $sub->pending_order_id;
	}

	/**
	 * The box a subscriber gets on a delivery (what they chose for it, their default box or the merchant's).
	 *
	 * @param object $sub    Subscription.
	 * @param array  $config Configuration.
	 * @param int    $time   Delivery time.
	 * @return array Selection.
	 */
	public static function for_delivery( $sub, $config, $time ) {
		$cycle = self::get( $sub->id, self::cycle( $time ) );
		if ( is_array( $cycle ) && ! isset( $cycle['taken'] ) ) {
			return $cycle;
		}
		$default = self::get( $sub->id, 'default' );
		$must    = $config['deadline']['enabled'] && $config['deadline']['every'] && RINNOVOBB_Config::interactive( $config );
		if ( $must && $config['rules']['autofill'] && $config['rules']['defaults'] ) {
			// Customers choose for every delivery: nothing chosen = the merchant's default box.
			return RINNOVOBB_Engine::defaults_selection( $config, is_array( $default ) && isset( $default['prefs'] ) ? $default['prefs'] : array() );
		}
		if ( is_array( $default ) ) {
			// Products that went out of stock since: replace them or leave them out.
			$out = $default;
			foreach ( (array) $out['items'] as $i => $item ) {
				$p = wc_get_product( $item['variation_id'] ? $item['variation_id'] : $item['product_id'] );
				if ( ! RINNOVOBB_Engine::in_stock( $p, (int) $item['qty'] ) ) {
					$sub_p = $config['rules']['substitute'] ? RINNOVOBB_Engine::substitute( $config, (int) $item['product_id'] ) : null;
					if ( $sub_p ) {
						$out['items'][ $i ]['product_id']   = $sub_p->get_id();
						$out['items'][ $i ]['variation_id'] = 0;
						$out['items'][ $i ]['substituted']  = (int) $item['product_id'];
					} elseif ( apply_filters( 'rinnovobb_drop_out_of_stock', true, $item, $sub ) ) {
						unset( $out['items'][ $i ] );
					}
				}
			}
			$out['items'] = array_values( (array) $out['items'] );
			return $out;
		}
		return $config['rules']['autofill'] ? RINNOVOBB_Engine::defaults_selection( $config ) : array(
			'items' => array(),
			'prefs' => array(),
			'auto'  => false,
		);
	}

	/**
	 * Take the box for a renewal order: the same order always gets the same box (safe when retried).
	 *
	 * @param object     $sub     Subscription.
	 * @param WC_Order   $order   Renewal order.
	 * @param WC_Product $product Box product.
	 * @param int        $time    Delivery time.
	 * @return array Snapshot.
	 */
	public static function take( $sub, $order, $product, $time ) {
		global $wpdb;
		$used = $wpdb->get_var( $wpdb->prepare( "SELECT data FROM %i WHERE subscription_id = %d AND order_id = %d AND status = 'used' LIMIT 1", self::table(), $sub->id, $order->get_id() ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
		if ( $used ) {
			$snap = json_decode( (string) $used, true );
			if ( is_array( $snap ) ) {
				return $snap;
			}
		}
		$config = RINNOVOBB_Config::get( $product );
		$sel    = self::for_delivery( $sub, $config, $time );
		$snap   = RINNOVOBB_Engine::snapshot( $config, $sel, $product, $time );
		self::put( $sub->id, self::cycle( $time ), $snap, 'used', $order->get_id() );
		self::put( $sub->id, 'last', $snap );
		return $snap;
	}

	/**
	 * Hourly: remind subscribers to choose their box before the deadline.
	 */
	public static function reminders() {
		$subs = RINNOVO_Subscriptions::query( "status IN ('active','trial') AND next_payment IS NOT NULL", 500 );
		$sent = 0;
		foreach ( $subs as $sub ) {
			if ( $sent >= 50 ) {
				break;
			}
			$product = wc_get_product( (int) $sub->product_id );
			if ( ! $product || ! RINNOVOBB_Config::active( $product ) ) {
				continue;
			}
			$config = RINNOVOBB_Config::get( $product );
			if ( ! $config['deadline']['enabled'] || ! $config['deadline']['notify'] || ! RINNOVOBB_Config::interactive( $config ) ) {
				continue;
			}
			$time  = self::next_time( $sub );
			$cycle = self::cycle( $time );
			$cut   = self::deadline( $config, $time );
			$open  = self::opens( $config, $time );
			if ( time() < $open || time() >= $cut || self::get( $sub->id, $cycle ) ) {
				continue;
			}
			$row = self::row( $sub->id, 'default' );
			if ( $row && $row->reminded === $cycle ) {
				continue;
			}
			$user = get_userdata( (int) $sub->user_id );
			if ( ! $user ) {
				continue;
			}
			if ( ! $row ) {
				self::put( $sub->id, 'default', array( 'items' => array(), 'prefs' => array() ) );
			}
			global $wpdb;
			$wpdb->update( self::table(), array( 'reminded' => $cycle ), array( 'subscription_id' => $sub->id, 'cycle' => 'default' ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			self::email(
				$user->user_email,
				__( 'Choose your next box', self::TD ),
				sprintf(
					/* translators: 1: product, 2: delivery date, 3: deadline. */
					__( 'The menu for your %1$s delivery of %2$s is ready. Choose your items before %3$s.', self::TD ),
					$product->get_name(),
					wp_date( get_option( 'date_format' ), $time ),
					wp_date( get_option( 'date_format' ) . ' ' . get_option( 'time_format' ), $cut )
				) . ' ' . ( $config['rules']['autofill'] ? __( 'If you don\'t choose, we\'ll pack our selection for you.', self::TD ) : '' ),
				RINNOVOBB_Account::url()
			);
			++$sent;
		}
	}

	/**
	 * Send a short WooCommerce-styled email.
	 *
	 * @param string $to      Address.
	 * @param string $subject Subject.
	 * @param string $text    Text.
	 * @param string $url     Button link.
	 */
	public static function email( $to, $subject, $text, $url ) {
		$mailer = WC()->mailer();
		$mailer->send( $to, $subject, $mailer->wrap_message( $subject, '<p>' . esc_html( $text ) . '</p><p><a href="' . esc_url( $url ) . '">' . esc_html__( 'Choose my box', self::TD ) . '</a></p>' ) );
	}

	/**
	 * Tell subscribers of a product that its menu changed.
	 *
	 * @param WC_Product $product Product.
	 */
	public static function menu_changed( $product ) {
		$subs = RINNOVO_Subscriptions::query( "status IN ('active','trial') AND product_id = " . (int) $product->get_id(), 300 );
		foreach ( $subs as $sub ) {
			$user = get_userdata( (int) $sub->user_id );
			if ( $user ) {
				/* translators: %s: product. */
				self::email( $user->user_email, sprintf( __( 'New menu for %s', self::TD ), $product->get_name() ), __( 'We have updated the menu of your subscription box. Have a look and change your next box if you like.', self::TD ), RINNOVOBB_Account::url() );
			}
		}
	}
}
