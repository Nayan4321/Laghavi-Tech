<?php
/**
 * Team memberships: one buyer (a school, company or family) pays, and invites people to share the
 * membership. Give a product "Team seats" in its General tab; seats = that number × quantity bought.
 * Team members have the tier while the buyer's subscription is active, and lose it when it ends or
 * when they are removed.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Teams.
 */
class RINNOVOMEM_Teams {

	const TD         = 'rinnovo-membership-content-pro';
	const DB_VERSION = '1';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'woocommerce_product_options_general_product_data', array( __CLASS__, 'product_field' ), 40 );
		add_action( 'woocommerce_process_product_meta', array( __CLASS__, 'save_product_field' ), 40 );
		add_action( 'template_redirect', array( __CLASS__, 'handle' ), 6 );
	}

	/**
	 * Table.
	 *
	 * @return string
	 */
	public static function table() {
		global $wpdb;
		return $wpdb->prefix . 'rinnovomem_seats';
	}

	/**
	 * Create the table.
	 */
	public static function install() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';
		$table = self::table();
		dbDelta(
			"CREATE TABLE {$table} (
				id bigint(20) unsigned NOT NULL AUTO_INCREMENT,
				subscription_id bigint(20) unsigned NOT NULL DEFAULT 0,
				owner_id bigint(20) unsigned NOT NULL DEFAULT 0,
				email varchar(200) NOT NULL DEFAULT '',
				user_id bigint(20) unsigned NOT NULL DEFAULT 0,
				token varchar(64) NOT NULL DEFAULT '',
				status varchar(20) NOT NULL DEFAULT 'invited',
				created_at datetime NOT NULL,
				updated_at datetime NOT NULL,
				PRIMARY KEY  (id),
				KEY subscription_id (subscription_id),
				KEY user_id (user_id),
				KEY token (token)
			) {$wpdb->get_charset_collate()};"
		);
		update_option( 'rinnovomem_db_version', self::DB_VERSION );
	}

	/**
	 * Seats a subscription has.
	 *
	 * @param object $sub Subscription.
	 * @return int
	 */
	public static function seats( $sub ) {
		$product = (int) $sub->product_id;
		$seats   = (int) get_post_meta( $product, '_rinnovomem_seats', true );
		if ( ! $seats && wp_get_post_parent_id( $product ) ) {
			$seats = (int) get_post_meta( wp_get_post_parent_id( $product ), '_rinnovomem_seats', true );
		}
		return $seats * max( 1, (int) $sub->quantity );
	}

	/**
	 * Seats of a subscription (invited and active).
	 *
	 * @param int $sub_id Subscription.
	 * @return object[]
	 */
	public static function for_subscription( $sub_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE subscription_id = %d AND status IN ('invited','active') ORDER BY id", self::table(), $sub_id ) );
	}

	/**
	 * Active seats a user holds.
	 *
	 * @param int $user_id User.
	 * @return object[]
	 */
	public static function seats_for_user( $user_id ) {
		global $wpdb;
		static $ready = null;
		if ( null === $ready ) {
			$ready = get_option( 'rinnovomem_db_version' ) === self::DB_VERSION;
		}
		if ( ! $ready || ! $user_id ) {
			return array();
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return (array) $wpdb->get_results( $wpdb->prepare( "SELECT * FROM %i WHERE user_id = %d AND status = 'active'", self::table(), $user_id ) );
	}

	/**
	 * Users on a subscription's team.
	 *
	 * @param int $sub_id Subscription.
	 * @return int[]
	 */
	public static function member_ids( $sub_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		return array_map( 'intval', (array) $wpdb->get_col( $wpdb->prepare( "SELECT user_id FROM %i WHERE subscription_id = %d AND status = 'active' AND user_id > 0", self::table(), $sub_id ) ) );
	}

	/**
	 * Move a team to a new subscription (plan switch).
	 *
	 * @param int $from Old subscription.
	 * @param int $to   New subscription.
	 */
	public static function move( $from, $to ) {
		global $wpdb;
		$wpdb->update( self::table(), array( 'subscription_id' => (int) $to ), array( 'subscription_id' => (int) $from ) ); // phpcs:ignore WordPress.DB.DirectDatabaseQuery
	}

	/**
	 * Invite someone.
	 *
	 * @param object $sub   Subscription.
	 * @param string $email Email.
	 * @return true|WP_Error
	 */
	public static function invite( $sub, $email ) {
		global $wpdb;
		$email = sanitize_email( $email );
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'rinnovomem_team', __( 'Please enter a valid email address.', self::TD ) );
		}
		$seats = self::for_subscription( $sub->id );
		if ( count( $seats ) >= self::seats( $sub ) ) {
			return new WP_Error( 'rinnovomem_team', __( 'All seats are taken. Remove someone, or buy more seats.', self::TD ) );
		}
		foreach ( $seats as $seat ) {
			if ( strtolower( $seat->email ) === strtolower( $email ) ) {
				return new WP_Error( 'rinnovomem_team', __( 'This person is already invited.', self::TD ) );
			}
		}
		$owner = get_userdata( (int) $sub->user_id );
		if ( $owner && strtolower( $owner->user_email ) === strtolower( $email ) ) {
			return new WP_Error( 'rinnovomem_team', __( 'You are already a member yourself.', self::TD ) );
		}
		$token = strtolower( wp_generate_password( 32, false ) );
		$now   = current_time( 'mysql', true );
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->insert(
			self::table(),
			array(
				'subscription_id' => (int) $sub->id,
				'owner_id'        => (int) $sub->user_id,
				'email'           => $email,
				'token'           => $token,
				'status'          => 'invited',
				'created_at'      => $now,
				'updated_at'      => $now,
			)
		);
		$link    = add_query_arg( 'rinnovomem_join', $token, home_url( '/' ) );
		$name    = $owner ? $owner->display_name : get_bloginfo( 'name' );
		$color   = get_option( 'woocommerce_email_base_color', '#7f54b3' );
		/* translators: 1: person, 2: membership, 3: site. */
		$body    = '<p>' . esc_html( sprintf( __( '%1$s invited you to join their %2$s membership on %3$s.', self::TD ), $name, $sub->name, get_bloginfo( 'name' ) ) ) . '</p>';
		$body   .= '<p style="margin:24px 0"><a href="' . esc_url( $link ) . '" style="background:' . esc_attr( $color ) . ';color:#ffffff;padding:12px 22px;border-radius:6px;text-decoration:none;font-weight:bold;display:inline-block">' . esc_html__( 'Accept the invitation', self::TD ) . '</a></p>';
		$body   .= '<p>' . esc_html__( 'Log in or create an account with this email address to accept.', self::TD ) . '</p>';
		$mailer  = WC()->mailer();
		/* translators: %s: site name. */
		$mailer->send( $email, sprintf( __( 'You are invited to join a membership on %s', self::TD ), get_bloginfo( 'name' ) ), $mailer->wrap_message( __( 'You are invited', self::TD ), $body ) );
		return true;
	}

	/**
	 * Remove a seat.
	 *
	 * @param int $seat_id  Seat.
	 * @param int $owner_id Owner (must match).
	 * @return bool
	 */
	public static function remove( $seat_id, $owner_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$seat = $wpdb->get_row( $wpdb->prepare( 'SELECT * FROM %i WHERE id = %d', self::table(), $seat_id ) );
		if ( ! $seat || ( (int) $seat->owner_id !== (int) $owner_id && (int) $seat->user_id !== (int) $owner_id ) ) {
			return false;
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array(
				'status'     => 'removed',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $seat_id )
		);
		if ( $seat->user_id ) {
			RINNOVOMEM_Members::sync( (int) $seat->user_id );
		}
		return true;
	}

	/**
	 * Accept an invitation.
	 *
	 * @param string $token   Token.
	 * @param int    $user_id User.
	 * @return true|WP_Error
	 */
	public static function accept( $token, $user_id ) {
		global $wpdb;
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$seat = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM %i WHERE token = %s AND status = 'invited'", self::table(), $token ) );
		$user = get_userdata( $user_id );
		if ( ! $seat || ! $user ) {
			return new WP_Error( 'rinnovomem_team', __( 'This invitation is no longer valid.', self::TD ) );
		}
		if ( strtolower( $user->user_email ) !== strtolower( $seat->email ) ) {
			/* translators: %s: email. */
			return new WP_Error( 'rinnovomem_team', sprintf( __( 'This invitation is for %s. Please log in with that email address.', self::TD ), $seat->email ) );
		}
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery
		$wpdb->update(
			self::table(),
			array(
				'user_id'    => (int) $user_id,
				'status'     => 'active',
				'token'      => '',
				'updated_at' => current_time( 'mysql', true ),
			),
			array( 'id' => (int) $seat->id )
		);
		RINNOVOMEM_Members::sync( $user_id );
		return true;
	}

	/**
	 * "Team seats" on products.
	 */
	public static function product_field() {
		echo '<div class="options_group">';
		woocommerce_wp_text_input(
			array(
				'id'                => '_rinnovomem_seats',
				'label'             => __( 'Team seats', 'rinnovo-membership-content-pro' ),
				'type'              => 'number',
				'custom_attributes' => array( 'min' => 0 ),
				'description'       => __( 'For membership products: how many people the buyer can invite to share it (per quantity bought). 0 = a personal membership.', 'rinnovo-membership-content-pro' ),
				'desc_tip'          => true,
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
		if ( ! isset( $_POST['woocommerce_meta_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['woocommerce_meta_nonce'] ) ), 'woocommerce_save_data' ) || ! current_user_can( 'edit_product', $product_id ) ) {
			return;
		}
		$seats = isset( $_POST['_rinnovomem_seats'] ) ? absint( $_POST['_rinnovomem_seats'] ) : 0;
		if ( $seats ) {
			update_post_meta( $product_id, '_rinnovomem_seats', $seats );
		} else {
			delete_post_meta( $product_id, '_rinnovomem_seats' );
		}
	}

	/**
	 * Invitation links and the team forms.
	 */
	public static function handle() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- The invitation token is the secret.
		$token = isset( $_GET['rinnovomem_join'] ) ? sanitize_key( wp_unslash( $_GET['rinnovomem_join'] ) ) : '';
		if ( '' !== $token ) {
			if ( ! is_user_logged_in() ) {
				wp_safe_redirect( wp_login_url( add_query_arg( 'rinnovomem_join', $token, home_url( '/' ) ) ) );
				exit;
			}
			$result = self::accept( $token, get_current_user_id() );
			wc_add_notice( is_wp_error( $result ) ? $result->get_error_message() : __( 'Welcome! You are now a member of the team.', self::TD ), is_wp_error( $result ) ? 'error' : 'success' );
			wp_safe_redirect( wc_get_account_endpoint_url( RINNOVOMEM_Account::ENDPOINT ) );
			exit;
		}
		if ( empty( $_POST['rinnovomem_team_do'] ) || ! is_user_logged_in() ) {
			return;
		}
		check_admin_referer( 'rinnovomem_team', 'rinnovomem_team_nonce' );
		$todo = sanitize_key( wp_unslash( $_POST['rinnovomem_team_do'] ) );
		$user = get_current_user_id();
		if ( 'invite' === $todo ) {
			$sub = RINNOVO_Subscriptions::get( isset( $_POST['rinnovomem_sub'] ) ? absint( $_POST['rinnovomem_sub'] ) : 0 );
			if ( ! $sub || (int) $sub->user_id !== $user || ! RINNOVOMEM_Members::sub_gives_access( $sub ) ) {
				wc_add_notice( __( 'Subscription not found.', self::TD ), 'error' );
			} else {
				$r = self::invite( $sub, isset( $_POST['rinnovomem_email'] ) ? sanitize_email( wp_unslash( $_POST['rinnovomem_email'] ) ) : '' );
				wc_add_notice( is_wp_error( $r ) ? $r->get_error_message() : __( 'Invitation sent.', self::TD ), is_wp_error( $r ) ? 'error' : 'success' );
			}
		} elseif ( 'remove' === $todo ) {
			self::remove( isset( $_POST['rinnovomem_seat'] ) ? absint( $_POST['rinnovomem_seat'] ) : 0, $user );
			wc_add_notice( __( 'Removed from the team.', self::TD ) );
		}
		wp_safe_redirect( wc_get_account_endpoint_url( RINNOVOMEM_Account::ENDPOINT ) );
		exit;
	}
}
