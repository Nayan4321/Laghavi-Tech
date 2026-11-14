<?php
/**
 * Licenses follow the subscription: when a customer moves to another plan (1 site to 5 sites, monthly to
 * yearly) the license's site limit changes with it, and every change, renewal and status change is kept in
 * the license's history (an audit trail for support and disputes).
 *
 * @package RinnovoSubscriptionsLicensesPro
 */

defined( 'ABSPATH' ) || exit;

/**
 * License entitlements and history.
 */
class RINNOVOPRO_License_History {

	const TD = 'rinnovo-subscriptions-licenses-pro';

	/**
	 * History table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rinnovopro_license_log';
	}

	/**
	 * Hook everything up.
	 */
	public static function init() {
		self::install();
		add_action( 'rinnovo_subscription_updated', array( __CLASS__, 'subscription_updated' ), 10, 2 );
		add_action( 'rinnovo_subscription_status_changed', array( __CLASS__, 'status_changed' ), 10, 2 );
		add_action( 'woocommerce_payment_complete', array( __CLASS__, 'renewal_paid' ), 30 );
		add_action( 'rinnovo_license_reissued', array( __CLASS__, 'reissued' ) );
		add_action( 'rinnovo_license_admin_row', array( __CLASS__, 'admin_row' ) );
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		if ( get_option( 'rinnovopro_license_log_db' ) === '1' ) {
			return;
		}
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t = self::table();
		dbDelta(
			"CREATE TABLE {$t} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				subscription_id bigint(20) unsigned NOT NULL DEFAULT 0,
				event varchar(30) NOT NULL,
				detail varchar(255) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY license_id (license_id)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'rinnovopro_license_log_db', '1', false );
	}

	/**
	 * Add a history line.
	 *
	 * @param int    $license_id Licence.
	 * @param string $event      Event.
	 * @param string $detail     Detail.
	 * @param int    $sub_id     Subscription.
	 */
	public static function log( $license_id, $event, $detail = '', $sub_id = 0 ) {
		global $wpdb;
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
			self::table(),
			array(
				'license_id'      => (int) $license_id,
				'subscription_id' => (int) $sub_id,
				'event'           => substr( $event, 0, 30 ),
				'detail'          => substr( $detail, 0, 255 ),
				'user_id'         => get_current_user_id(),
				'created_at'      => current_time( 'mysql', true ),
			)
		);
	}

	/**
	 * Licenses bought with a subscription.
	 *
	 * @param object $sub Subscription.
	 * @return object[]
	 */
	private static function licenses_of( $sub ) {
		if ( ! $sub || ! class_exists( 'RINNOVO_Licenses' ) ) {
			return array();
		}
		return (array) RINNOVO_Licenses::for_order( (int) $sub->parent_order_id );
	}

	/**
	 * A subscription moved to another product or variation: the license's site limit follows the new plan.
	 *
	 * @param int   $id     Subscription.
	 * @param array $fields Changed fields.
	 */
	public static function subscription_updated( $id, $fields ) {
		if ( empty( $fields['product_id'] ) ) {
			return;
		}
		$sub = RINNOVO_Subscriptions::get( $id );
		foreach ( self::licenses_of( $sub ) as $license ) {
			$settings = RINNOVO_License_Product::settings( (int) $fields['product_id'] );
			$old      = (int) $license->sites_allowed;
			$new      = isset( $settings['sites'] ) ? (int) $settings['sites'] : $old;
			$changes  = array();
			if ( $new !== $old ) {
				$changes['sites_allowed'] = $new;
			}
			if ( (int) $license->product_id !== (int) $fields['product_id'] && 'product_variation' !== get_post_type( (int) $license->product_id ) ) {
				// Keys belong to the product (variations share their product's downloads): keep it.
				unset( $changes['product_id'] );
			}
			if ( $changes ) {
				RINNOVO_Licenses::update( $license->id, $changes );
			}
			$label = static function ( $n ) {
				return 0 === (int) $n ? __( 'unlimited sites', self::TD ) : sprintf( /* translators: %d: sites. */ _n( '%d site', '%d sites', (int) $n, self::TD ), (int) $n );
			};
			$kind = 0 === $new || ( 0 !== $old && $new > $old ) ? 'upgraded' : ( $new === $old ? 'plan_changed' : 'downgraded' );
			/* translators: 1: plan, 2: old limit, 3: new limit. */
			self::log( $license->id, $kind, sprintf( __( 'Plan changed to %1$s: %2$s → %3$s', self::TD ), html_entity_decode( get_the_title( (int) $fields['product_id'] ), ENT_QUOTES, 'UTF-8' ), $label( $old ), $label( $new ) ), $id );
			if ( 'downgraded' === $kind && $new > 0 && count( RINNOVO_Licenses::activations( $license->id ) ) > $new ) {
				/* translators: %d: sites allowed. */
				self::log( $license->id, 'over_limit', sprintf( __( 'More sites active than the new plan allows (%d): no new activations until some are removed.', self::TD ), $new ), $id );
			}
		}
	}

	/**
	 * Status changes of the subscription (on hold, cancelled, expired…) in the license history.
	 *
	 * @param object $sub  Subscription (with its new status).
	 * @param string $from Old status.
	 */
	public static function status_changed( $sub, $from = '' ) {
		$to = is_object( $sub ) ? (string) $sub->status : '';
		foreach ( self::licenses_of( is_object( $sub ) ? $sub : RINNOVO_Subscriptions::get( (int) $sub ) ) as $license ) {
			/* translators: 1: old status, 2: new status. */
			self::log( $license->id, 'subscription_' . sanitize_key( $to ), sprintf( __( 'Subscription %1$s → %2$s', self::TD ), $from, $to ), is_object( $sub ) ? (int) $sub->id : 0 );
		}
	}

	/**
	 * A renewal was paid.
	 *
	 * @param int $order_id Order.
	 */
	public static function renewal_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order || ! $order->get_meta( '_rinnovo_renewal' ) ) {
			return;
		}
		$sub = RINNOVO_Subscriptions::get( (int) $order->get_meta( '_rinnovo_subscription_id' ) );
		foreach ( self::licenses_of( $sub ) as $license ) {
			/* translators: %s: order number. */
			self::log( $license->id, 'renewed', sprintf( __( 'Renewal paid (order #%s)', self::TD ), $order->get_order_number() ), $sub ? (int) $sub->id : 0 );
		}
	}

	/**
	 * A new key was issued.
	 *
	 * @param object $license License.
	 */
	public static function reissued( $license ) {
		self::log( $license->id, 'reissued', __( 'New key issued; earlier activations removed', self::TD ) );
	}

	/**
	 * History of a license.
	 *
	 * @param int $license_id License.
	 * @return object[]
	 */
	public static function history( $license_id ) {
		global $wpdb;
		return (array) $wpdb->get_results( $wpdb->prepare( 'SELECT * FROM %i WHERE license_id = %d ORDER BY id DESC LIMIT 50', self::table(), $license_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Own table.
	}

	/**
	 * History under each license in WooCommerce > Licenses.
	 *
	 * @param object $license License.
	 */
	public static function admin_row( $license ) {
		$rows = self::history( (int) $license->id );
		if ( ! $rows ) {
			return;
		}
		echo '<details style="margin-top:6px"><summary>' . esc_html__( 'History', self::TD ) . ' (' . count( $rows ) . ')</summary><ul style="margin:6px 0 0 16px;list-style:disc">';
		foreach ( $rows as $row ) {
			echo '<li><small>' . esc_html( wp_date( get_option( 'date_format' ) . ' H:i', strtotime( $row->created_at . ' UTC' ) ) ) . '</small> ' . esc_html( $row->detail ) . '</li>';
		}
		echo '</ul></details>';
	}
}
