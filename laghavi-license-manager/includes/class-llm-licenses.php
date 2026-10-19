<?php
/**
 * License and activation storage.
 *
 * @package LaghaviLicenseManager
 */

defined( 'ABSPATH' ) || exit;

/**
 * Licenses.
 */
class LLM_Licenses {

	const DB_VERSION = '1';

	/**
	 * Table names.
	 *
	 * @return array{licenses: string, activations: string}
	 */
	public static function tables() {
		global $wpdb;
		return array(
			'licenses'    => $wpdb->prefix . 'llm_licenses',
			'activations' => $wpdb->prefix . 'llm_activations',
		);
	}

	/**
	 * Create the tables and the private folder for plugin zips.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$t       = self::tables();
		$charset = $wpdb->get_charset_collate();

		dbDelta(
			"CREATE TABLE {$t['licenses']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_key varchar(40) NOT NULL,
				product_id bigint(20) unsigned NOT NULL DEFAULT 0,
				order_id bigint(20) unsigned NOT NULL DEFAULT 0,
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(191) NOT NULL DEFAULT '',
				sites_allowed int(11) NOT NULL DEFAULT 1,
				expires_at datetime DEFAULT NULL,
				status varchar(20) NOT NULL DEFAULT 'active',
				created_at datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY license_key (license_key),
				KEY order_id (order_id),
				KEY user_id (user_id),
				KEY email (email)
			) {$charset};"
		);
		dbDelta(
			"CREATE TABLE {$t['activations']} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				license_id bigint(20) unsigned NOT NULL,
				site varchar(191) NOT NULL,
				created_at datetime NOT NULL,
				last_seen datetime NOT NULL,
				PRIMARY KEY  (id),
				UNIQUE KEY license_site (license_id, site)
			) {$charset};"
		);
		update_option( 'llm_db_version', self::DB_VERSION );
		self::private_dir();
	}

	/**
	 * Private folder for plugin zips: a random name, blocked from direct download.
	 *
	 * @return string Absolute path with trailing slash.
	 */
	public static function private_dir() {
		$name = get_option( 'llm_private_dir' );
		if ( ! $name ) {
			$name = 'laghavi-licenses-' . wp_generate_password( 16, false, false );
			update_option( 'llm_private_dir', $name, false );
		}
		$uploads = wp_upload_dir( null, false );
		$dir     = trailingslashit( $uploads['basedir'] ) . $name . '/';
		if ( ! is_dir( $dir ) ) {
			wp_mkdir_p( $dir );
		}
		if ( ! file_exists( $dir . '.htaccess' ) ) {
			file_put_contents( $dir . '.htaccess', "Require all denied\nDeny from all\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			file_put_contents( $dir . 'index.php', "<?php\n// Silence is golden.\n" ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
		}
		return $dir;
	}

	/**
	 * A new random key like ABCDE-FGHJK-LMNPQ-RSTUV (no 0/O/1/I to avoid typos).
	 *
	 * @return string
	 */
	public static function generate_key() {
		$chars = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
		do {
			$groups = array();
			for ( $g = 0; $g < 4; $g++ ) {
				$group = '';
				for ( $i = 0; $i < 5; $i++ ) {
					$group .= $chars[ random_int( 0, strlen( $chars ) - 1 ) ];
				}
				$groups[] = $group;
			}
			$key = implode( '-', $groups );
		} while ( self::get_by_key( $key ) );
		return $key;
	}

	/**
	 * Create a license.
	 *
	 * @param array $data product_id, order_id, user_id, email, sites_allowed, days (0 = lifetime).
	 * @return object|null The new license.
	 */
	public static function create( $data ) {
		global $wpdb;
		$days = isset( $data['days'] ) ? (int) $data['days'] : 365;
		$key  = self::generate_key();
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::tables()['licenses'],
			array(
				'license_key'   => $key,
				'product_id'    => (int) $data['product_id'],
				'order_id'      => isset( $data['order_id'] ) ? (int) $data['order_id'] : 0,
				'user_id'       => isset( $data['user_id'] ) ? (int) $data['user_id'] : 0,
				'email'         => isset( $data['email'] ) ? sanitize_email( $data['email'] ) : '',
				'sites_allowed' => isset( $data['sites_allowed'] ) ? (int) $data['sites_allowed'] : 1,
				'expires_at'    => $days > 0 ? gmdate( 'Y-m-d H:i:s', time() + $days * DAY_IN_SECONDS ) : null,
				'status'        => 'active',
				'created_at'    => current_time( 'mysql', true ),
			)
		);
		return self::get_by_key( $key );
	}

	/**
	 * Find a license by key.
	 *
	 * @param string $key License key.
	 * @return object|null
	 */
	public static function get_by_key( $key ) {
		global $wpdb;
		$t = self::tables()['licenses'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE license_key = %s", strtoupper( trim( (string) $key ) ) ) );
	}

	/**
	 * Find a license by ID.
	 *
	 * @param int $id License ID.
	 * @return object|null
	 */
	public static function get( $id ) {
		global $wpdb;
		$t = self::tables()['licenses'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE id = %d", $id ) );
	}

	/**
	 * Licenses of an order.
	 *
	 * @param int $order_id Order ID.
	 * @return object[]
	 */
	public static function for_order( $order_id ) {
		global $wpdb;
		$t = self::tables()['licenses'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE order_id = %d ORDER BY id", $order_id ) );
	}

	/**
	 * Licenses of a customer (by account or by email).
	 *
	 * @param int    $user_id User ID.
	 * @param string $email   Email.
	 * @return object[]
	 */
	public static function for_customer( $user_id, $email ) {
		global $wpdb;
		$t = self::tables()['licenses'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE user_id = %d OR ( email <> '' AND email = %s ) ORDER BY id DESC", $user_id, $email ) );
	}

	/**
	 * Update fields of a license.
	 *
	 * @param int   $id     License ID.
	 * @param array $fields Fields.
	 */
	public static function update( $id, $fields ) {
		global $wpdb;
		$wpdb->update( self::tables()['licenses'], $fields, array( 'id' => (int) $id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Sites a license is active on.
	 *
	 * @param int $license_id License ID.
	 * @return object[]
	 */
	public static function activations( $license_id ) {
		global $wpdb;
		$t = self::tables()['activations'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$t} WHERE license_id = %d ORDER BY id", $license_id ) );
	}

	/**
	 * Normalize a site address so http/https, "www." and trailing slashes don't count as different sites.
	 *
	 * @param string $url Site URL.
	 * @return string
	 */
	public static function normalize_site( $url ) {
		$url   = strtolower( trim( (string) $url ) );
		$parts = wp_parse_url( false === strpos( $url, '://' ) ? 'http://' . $url : $url );
		if ( empty( $parts['host'] ) ) {
			return '';
		}
		$host = preg_replace( '/^www\./', '', $parts['host'] );
		$path = isset( $parts['path'] ) ? untrailingslashit( $parts['path'] ) : '';
		return substr( $host . ( isset( $parts['port'] ) ? ':' . $parts['port'] : '' ) . $path, 0, 191 );
	}

	/**
	 * Find a license's activation for a site.
	 *
	 * @param int    $license_id License ID.
	 * @param string $site       Normalized site.
	 * @return object|null
	 */
	public static function activation( $license_id, $site ) {
		global $wpdb;
		$t = self::tables()['activations'];
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		return $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$t} WHERE license_id = %d AND site = %s", $license_id, $site ) );
	}

	/**
	 * Activate a site.
	 *
	 * @param int    $license_id License ID.
	 * @param string $site       Normalized site.
	 */
	public static function add_activation( $license_id, $site ) {
		global $wpdb;
		$now = current_time( 'mysql', true );
		$wpdb->insert( // phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			self::tables()['activations'],
			array(
				'license_id' => $license_id,
				'site'       => $site,
				'created_at' => $now,
				'last_seen'  => $now,
			)
		);
	}

	/**
	 * Note that a site checked in.
	 *
	 * @param int $activation_id Activation ID.
	 */
	public static function touch_activation( $activation_id ) {
		global $wpdb;
		$wpdb->update( self::tables()['activations'], array( 'last_seen' => current_time( 'mysql', true ) ), array( 'id' => $activation_id ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Remove a site.
	 *
	 * @param int    $license_id License ID.
	 * @param string $site       Normalized site.
	 */
	public static function remove_activation( $license_id, $site ) {
		global $wpdb;
		$wpdb->delete( self::tables()['activations'], array( 'license_id' => $license_id, 'site' => $site ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Whether a license has passed its expiry date.
	 *
	 * @param object $license License.
	 * @return bool
	 */
	public static function is_expired( $license ) {
		return ! empty( $license->expires_at ) && strtotime( $license->expires_at . ' UTC' ) < time();
	}

	/**
	 * The subscription a license was bought with (Laghavi Subscriptions), if any.
	 *
	 * @param object $license License.
	 * @return object|null
	 */
	public static function subscription( $license ) {
		if ( ! class_exists( 'LSUB_Subscriptions' ) || empty( $license->order_id ) ) {
			return null;
		}
		$subs = LSUB_Subscriptions::for_order( (int) $license->order_id );
		return $subs ? $subs[0] : null;
	}

	/**
	 * Status shown to people: active, expired or revoked.
	 *
	 * Keys bought with a subscription follow it: active while it is paid (also after the customer
	 * cancels, until the paid period ends), expired once it has ended.
	 *
	 * @param object $license License.
	 * @return string
	 */
	public static function status( $license ) {
		if ( 'active' !== $license->status ) {
			return $license->status;
		}
		$sub = self::subscription( $license );
		if ( $sub ) {
			if ( 'pending-cancel' === $sub->status ) {
				return $sub->next_payment && strtotime( $sub->next_payment . ' UTC' ) > time() ? 'active' : 'expired';
			}
			return in_array( $sub->status, array( 'pending', 'trial', 'active', 'on-hold' ), true ) ? 'active' : 'expired';
		}
		return self::is_expired( $license ) ? 'expired' : 'active';
	}

	/**
	 * Human readable status.
	 *
	 * @param string $status Status.
	 * @return string
	 */
	public static function status_label( $status ) {
		$labels = array(
			'active'  => __( 'Active', 'laghavi-license-manager' ),
			'expired' => __( 'Expired', 'laghavi-license-manager' ),
			'revoked' => __( 'Revoked', 'laghavi-license-manager' ),
		);
		return isset( $labels[ $status ] ) ? $labels[ $status ] : $status;
	}

	/**
	 * Expiry date for display.
	 *
	 * @param object $license License.
	 * @return string
	 */
	public static function expiry_label( $license ) {
		$sub = self::subscription( $license );
		if ( $sub ) {
			$date = static function ( $mysql ) {
				return $mysql ? wp_date( get_option( 'date_format' ), strtotime( $mysql . ' UTC' ) ) : '';
			};
			switch ( $sub->status ) {
				case 'pending-cancel':
					/* translators: %s: date. */
					return sprintf( __( 'Until %s (subscription cancelled, will not renew)', 'laghavi-license-manager' ), $date( $sub->next_payment ) );
				case 'cancelled':
				case 'expired':
					/* translators: %s: date. */
					return sprintf( __( 'Ended %s', 'laghavi-license-manager' ), $date( $sub->end_date ) );
				default:
					return $sub->next_payment
						/* translators: %s: date. */
						? sprintf( __( 'Renews automatically on %s', 'laghavi-license-manager' ), $date( $sub->next_payment ) )
						: __( 'While your subscription is active', 'laghavi-license-manager' );
			}
		}
		return empty( $license->expires_at ) ? __( 'Lifetime', 'laghavi-license-manager' ) : wp_date( get_option( 'date_format' ), strtotime( $license->expires_at . ' UTC' ) );
	}

	/**
	 * Whether a user owns a license (by account, or by the email used to buy it).
	 *
	 * @param object  $license License.
	 * @param WP_User $user    User.
	 * @return bool
	 */
	public static function owned_by( $license, $user ) {
		return $user && $user->ID && ( (int) $license->user_id === (int) $user->ID || ( '' !== $license->email && strtolower( $license->email ) === strtolower( $user->user_email ) ) );
	}

	/**
	 * Path of the zip for a license's product, or '' if none is uploaded.
	 *
	 * @param object $license License.
	 * @return string
	 */
	public static function file_path( $license ) {
		$settings = LLM_Product::settings( $license->product_id );
		if ( '' === $settings['file'] ) {
			return '';
		}
		$path = self::private_dir() . basename( $settings['file'] );
		return is_readable( $path ) ? $path : '';
	}

	/**
	 * Download link for the customer (only works for the logged-in owner).
	 *
	 * @param object $license License.
	 * @return string
	 */
	public static function download_url( $license ) {
		return add_query_arg(
			array(
				'llm_download' => (int) $license->id,
				'_wpnonce'     => wp_create_nonce( 'llm_download_' . $license->id ),
			),
			wc_get_account_endpoint_url( LLM_Account::ENDPOINT )
		);
	}
}
