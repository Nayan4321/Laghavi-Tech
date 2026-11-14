<?php
/**
 * "My Membership" in My Account: plans and status, renewal date, credits left, courses, downloads this month,
 * what unlocks next, an upgrade suggestion and the shop's announcement, with quick links.
 *
 * Also: member notes for the shop, invite-only tiers, gift purchases, a REST API and webhooks.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * Dashboard and extras.
 */
class RINNOVOMEM_Dashboard {

	const TD       = 'rinnovo-membership-content-pro';
	const ENDPOINT = 'my-membership';

	/**
	 * Hooks.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'endpoint' ) );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 14 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'content' ) );
		add_shortcode( 'rinnovo_my_membership', array( __CLASS__, 'shortcode' ) );
		add_shortcode( 'rinnovo_courses', array( __CLASS__, 'courses_shortcode' ) );
		// Notes.
		add_action( 'show_user_profile', array( __CLASS__, 'note_field' ) );
		add_action( 'edit_user_profile', array( __CLASS__, 'note_field' ) );
		add_action( 'personal_options_update', array( __CLASS__, 'save_note' ) );
		add_action( 'edit_user_profile_update', array( __CLASS__, 'save_note' ) );
		// Invites.
		add_action( 'template_redirect', array( __CLASS__, 'catch_invite' ), 1 );
		add_filter( 'woocommerce_add_to_cart_validation', array( __CLASS__, 'invite_only' ), 9, 3 );
		// Gifts.
		add_action( 'woocommerce_before_add_to_cart_button', array( __CLASS__, 'gift_fields' ), 30 );
		add_filter( 'woocommerce_add_cart_item_data', array( __CLASS__, 'gift_cart' ), 20, 2 );
		add_filter( 'woocommerce_get_item_data', array( __CLASS__, 'gift_item_data' ), 20, 2 );
		add_action( 'woocommerce_checkout_create_order_line_item', array( __CLASS__, 'gift_line' ), 20, 3 );
		add_action( 'woocommerce_order_status_processing', array( __CLASS__, 'gift_paid' ), 30 );
		add_action( 'woocommerce_order_status_completed', array( __CLASS__, 'gift_paid' ), 30 );
		// API and webhooks.
		add_action( 'rest_api_init', array( __CLASS__, 'rest' ) );
		add_action( 'rinnovomem_access_changed', array( __CLASS__, 'hook_access' ), 10, 3 );
		add_action( 'rinnovomem_entitlement_added', array( __CLASS__, 'hook_entitlement' ), 10, 2 );
		add_filter( 'rinnovomem_admin_tabs', array( __CLASS__, 'tab' ) );
		add_action( 'rinnovomem_admin_tab_extras', array( __CLASS__, 'render' ) );
		add_action( 'rinnovomem_admin_save_extras', array( __CLASS__, 'save' ) );
	}

	/**
	 * Endpoint.
	 */
	public static function endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
		if ( get_option( 'rinnovomem_dashboard_flushed' ) !== RINNOVOMEM_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'rinnovomem_dashboard_flushed', RINNOVOMEM_VERSION, false );
		}
	}

	/**
	 * Query var.
	 *
	 * @param array $vars Vars.
	 * @return array
	 */
	public static function query_vars( $vars ) {
		$vars[ self::ENDPOINT ] = self::ENDPOINT;
		return $vars;
	}

	/**
	 * Menu item (members, and anyone with credits or courses).
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function menu( $items ) {
		$user = get_current_user_id();
		if ( ! $user || ( RINNOVOMEM_Members::rank( $user ) < 1 && ! RINNOVOMEM_Ledger::active( $user ) ) ) {
			return $items;
		}
		$out = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$out[ self::ENDPOINT ] = __( 'My Membership', self::TD );
			}
		}
		return isset( $out[ self::ENDPOINT ] ) ? $out : array( self::ENDPOINT => __( 'My Membership', self::TD ) ) + $items;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public static function title() {
		return __( 'My Membership', self::TD );
	}

	/**
	 * Page of the resource library (a page with [rinnovo_resources]).
	 *
	 * @return string
	 */
	public static function library_url() {
		$id = (int) get_transient( 'rinnovomem_library_page' );
		if ( ! $id ) {
			global $wpdb;
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery -- Cached for a day.
			$id = (int) $wpdb->get_var( "SELECT ID FROM {$wpdb->posts} WHERE post_status = 'publish' AND post_type IN ('page','post') AND post_content LIKE '%rinnovo_resources%' LIMIT 1" );
			set_transient( 'rinnovomem_library_page', $id ? $id : -1, DAY_IN_SECONDS );
		}
		return $id > 0 ? (string) get_permalink( $id ) : '';
	}

	/**
	 * Courses of a member: from tiers and bought on their own.
	 *
	 * @param int $user_id User.
	 * @return array[] title, url, source, until.
	 */
	public static function courses( $user_id ) {
		$out = array();
		foreach ( RINNOVOMEM_Tiers::all() as $id => $tier ) {
			if ( RINNOVOMEM_Members::has( $user_id, $id ) ) {
				foreach ( $tier['courses'] as $c ) {
					$out[ 'c' . $c ] = array(
						'title'  => get_the_title( $c ),
						'url'    => (string) get_permalink( $c ),
						/* translators: %s: tier. */
						'source' => sprintf( __( 'Included in %s', self::TD ), $tier['name'] ),
						'until'  => 0,
					);
				}
			}
		}
		foreach ( RINNOVOMEM_Ledger::active( $user_id, 'course' ) as $row ) {
			$is_cat = 0 === strpos( $row->target, 'cat:' );
			$term   = $is_cat ? get_term( (int) substr( $row->target, 4 ) ) : null;
			$out[ 'c' . $row->target ] = array(
				'title'  => $is_cat ? ( $term && ! is_wp_error( $term ) ? $term->name : $row->target ) : get_the_title( (int) $row->target ),
				'url'    => $is_cat ? ( $term && ! is_wp_error( $term ) ? (string) get_term_link( $term ) : '' ) : (string) get_permalink( (int) $row->target ),
				'source' => RINNOVOMEM_Ledger::source_label( $row->source ),
				'until'  => $row->ends_at ? strtotime( $row->ends_at . ' UTC' ) : 0,
			);
		}
		return $out;
	}

	/**
	 * The dashboard.
	 *
	 * @param int $user_id User.
	 * @return string
	 */
	public static function render_for( $user_id ) {
		$fmt  = get_option( 'date_format' );
		$html = '<div class="rinnovomem-dash">';
		$note = (string) RINNOVOMEM_Settings::get( 'announcement' );
		if ( '' !== trim( $note ) ) {
			$html .= '<div class="rinnovomem-dash__news woocommerce-info">' . wp_kses_post( wpautop( $note ) ) . '</div>';
		}

		// Plans.
		$held  = RINNOVOMEM_Members::held( $user_id );
		$html .= '<section><h3>' . esc_html__( 'My plans', self::TD ) . '</h3>';
		if ( ! $held ) {
			$html .= '<p>' . esc_html__( 'You have no membership at the moment.', self::TD ) . '</p>';
		}
		foreach ( $held as $tier => $h ) {
			$sub   = $h['sub'] ? RINNOVO_Subscriptions::get( (int) $h['sub'] ) : null;
			$group = RINNOVOMEM_Tiers::group( $tier );
			$html .= '<div class="rinnovomem-dash__plan"><strong>' . esc_html( RINNOVOMEM_Tiers::name( $tier ) ) . '</strong>' . ( '' !== $group ? ' <small>' . esc_html( RINNOVOMEM_Tiers::groups()[ $group ] ) . '</small>' : '' ) . '<br /><span>';
			if ( $sub ) {
				$html .= esc_html( RINNOVO_Subscriptions::status_label( $sub->status ) );
				if ( $sub->next_payment ) {
					/* translators: %s: date. */
					$html .= ' · ' . esc_html( sprintf( 'pending-cancel' === $sub->status ? __( 'ends %s', self::TD ) : __( 'renews %s', self::TD ), RINNOVO_Subscriptions::date_label( $sub->next_payment ) ) );
				}
			} else {
				$html .= esc_html( RINNOVOMEM_Ledger::source_label( $h['source'] ) );
			}
			/* translators: %s: date. */
			$html .= ' · ' . esc_html( sprintf( __( 'member since %s', self::TD ), wp_date( $fmt, (int) $h['since'] ) ) ) . '</span>';
			// Upgrade suggestion: the next tier of the same group.
			$next = null;
			foreach ( RINNOVOMEM_Tiers::all() as $id => $t ) {
				if ( $t['group'] === $group && $t['rank'] > RINNOVOMEM_Tiers::rank( $tier ) && ! RINNOVOMEM_Members::has( $user_id, $id ) && RINNOVOMEM_Tiers::products( $id ) ) {
					$next = $next ? $next : $id;
				}
			}
			if ( $next ) {
				/* translators: %s: tier. */
				$html .= '<br /><a href="' . esc_url( RINNOVOMEM_Tiers::join_url( $next ) ) . '">' . esc_html( sprintf( __( 'Upgrade to %s', self::TD ), RINNOVOMEM_Tiers::name( $next ) ) ) . ' →</a>';
			}
			$html .= '</div>';
		}
		$html .= '</section>';

		// Credits.
		$b = RINNOVOMEM_Credits::balance( $user_id );
		if ( $b['plan'] || $b['bought'] > 0 ) {
			$html .= '<section><h3>' . esc_html__( 'Download credits', self::TD ) . '</h3>' . RINNOVOMEM_Credits::balance_shortcode();
			if ( $b['plan'] && ! $b['unlimited'] ) {
				/* translators: %d: downloads. */
				$html .= '<p>' . esc_html( sprintf( _n( '%d download this period', '%d downloads this period', $b['used'], self::TD ), $b['used'] ) ) . '</p>';
			}
			$html .= '</section>';
		}

		// Courses.
		$courses = self::courses( $user_id );
		if ( $courses ) {
			$html .= '<section id="my-courses"><h3>' . esc_html__( 'My courses', self::TD ) . '</h3><ul>';
			foreach ( $courses as $c ) {
				/* translators: %s: date. */
				$html .= '<li><a href="' . esc_url( $c['url'] ) . '">' . esc_html( $c['title'] ) . '</a> <small>' . esc_html( $c['source'] . ( $c['until'] ? ' · ' . sprintf( __( 'until %s', self::TD ), wp_date( $fmt, $c['until'] ) ) : '' ) ) . '</small></li>';
			}
			$html .= '</ul></section>';
		}

		// Coming up.
		$up = RINNOVOMEM_Lifecycle::upcoming( $user_id );
		if ( $up ) {
			$html .= '<section><h3>' . esc_html__( 'Coming up for you', self::TD ) . '</h3><ul>';
			foreach ( $up as $u ) {
				$html .= '<li>' . esc_html( wp_date( $fmt, $u['at'] ) ) . ' — ' . esc_html( get_the_title( $u['post'] ) ) . '</li>';
			}
			$html .= '</ul></section>';
		}

		// Downloads.
		$log = RINNOVOMEM_Ledger::log_for( $user_id, 'download', 10 );
		if ( $log ) {
			$html .= '<section id="download-history"><h3>' . esc_html__( 'Download history', self::TD ) . '</h3><ul>';
			foreach ( $log as $row ) {
				$html .= '<li>' . esc_html( wp_date( $fmt, strtotime( $row->created_at . ' UTC' ) ) ) . ' — ' . ( get_post( (int) $row->object_id ) ? '<a href="' . esc_url( (string) get_permalink( (int) $row->object_id ) ) . '">' . esc_html( get_the_title( (int) $row->object_id ) ) . '</a>' : esc_html( $row->detail ) ) . '</li>';
			}
			$html .= '</ul></section>';
		}

		// Quick links.
		$links = array();
		if ( $courses ) {
			$links[] = array( '#my-courses', __( 'My Courses', self::TD ) );
		}
		if ( '' !== self::library_url() ) {
			$links[] = array( self::library_url(), __( 'Browse Resources', self::TD ) );
		}
		if ( $log ) {
			$links[] = array( '#download-history', __( 'Download History', self::TD ) );
		}
		$links[] = array( wc_get_account_endpoint_url( RINNOVO_Account::endpoint() ), __( 'Membership & Billing', self::TD ) );
		$links[] = array( wc_get_account_endpoint_url( RINNOVOMEM_Account::ENDPOINT ), RINNOVOMEM_Settings::text( 'account_label' ) );
		$html   .= '<p class="rinnovomem-dash__links">';
		foreach ( $links as $l ) {
			$html .= '<a class="button" href="' . esc_url( $l[0] ) . '">' . esc_html( $l[1] ) . '</a> ';
		}
		return $html . '</p></div>';
	}

	/**
	 * My Account page.
	 */
	public static function content() {
		wp_enqueue_style( 'rinnovomem' );
		echo self::render_for( get_current_user_id() ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Escaped while built.
	}

	/**
	 * [rinnovo_my_membership].
	 *
	 * @return string
	 */
	public static function shortcode() {
		return is_user_logged_in() ? self::render_for( get_current_user_id() ) : '';
	}

	/**
	 * [rinnovo_courses]: the member's courses.
	 *
	 * @return string
	 */
	public static function courses_shortcode() {
		$user = get_current_user_id();
		if ( ! $user ) {
			return '';
		}
		$html = '<ul class="rinnovomem-courses">';
		foreach ( self::courses( $user ) as $c ) {
			$html .= '<li><a href="' . esc_url( $c['url'] ) . '">' . esc_html( $c['title'] ) . '</a></li>';
		}
		return $html . '</ul>';
	}

	// ---------------------------------------------------------------- Notes.

	/**
	 * Shop note on a member's profile.
	 *
	 * @param WP_User $user User.
	 */
	public static function note_field( $user ) {
		if ( ! current_user_can( 'manage_woocommerce' ) ) {
			return;
		}
		echo '<h2>' . esc_html__( 'Membership', self::TD ) . '</h2><table class="form-table"><tr><th><label for="rinnovomem_note">' . esc_html__( 'Note for the team', self::TD ) . '</label></th><td><textarea id="rinnovomem_note" name="rinnovomem_note" rows="3" class="large-text">' . esc_textarea( (string) get_user_meta( $user->ID, '_rinnovomem_note', true ) ) . '</textarea><p class="description">' . esc_html__( 'Only the shop sees this.', self::TD ) . '</p></td></tr><tr><th>' . esc_html__( 'Access and why', self::TD ) . '</th><td>';
		wp_nonce_field( 'rinnovomem_note_' . $user->ID, 'rinnovomem_note_nonce' );
		$rows = RINNOVOMEM_Ledger::all( $user->ID );
		if ( ! $rows ) {
			echo '<em>' . esc_html__( 'No entitlements recorded yet (subscriptions are worked out live).', self::TD ) . '</em>';
		}
		foreach ( array_slice( $rows, 0, 30 ) as $r ) {
			echo '<div>' . esc_html( $r->kind . ': ' . ( 'tier' === $r->kind ? RINNOVOMEM_Tiers::name( $r->target ) : ( 'course' === $r->kind ? RINNOVOMEM_Courses::label( $r->target ) : $r->target ) ) . ' — ' . RINNOVOMEM_Ledger::source_label( $r->source ) . ( $r->source_id ? ' #' . $r->source_id : '' ) . ' · ' . $r->status . ' · ' . wp_date( get_option( 'date_format' ), strtotime( $r->starts_at . ' UTC' ) ) . ( $r->ends_at ? ' → ' . wp_date( get_option( 'date_format' ), strtotime( $r->ends_at . ' UTC' ) ) : '' ) ) . '</div>';
		}
		echo '</td></tr></table>';
	}

	/**
	 * Save the note.
	 *
	 * @param int $user_id User.
	 */
	public static function save_note( $user_id ) {
		if ( ! current_user_can( 'manage_woocommerce' ) || ! isset( $_POST['rinnovomem_note_nonce'] ) || ! wp_verify_nonce( sanitize_key( wp_unslash( $_POST['rinnovomem_note_nonce'] ) ), 'rinnovomem_note_' . $user_id ) ) {
			return;
		}
		update_user_meta( $user_id, '_rinnovomem_note', isset( $_POST['rinnovomem_note'] ) ? sanitize_textarea_field( wp_unslash( $_POST['rinnovomem_note'] ) ) : '' );
	}

	// ---------------------------------------------------------------- Invite-only tiers.

	/**
	 * Remember an invite code from a link (?invite=CODE).
	 */
	public static function catch_invite() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- An invite link.
		if ( isset( $_GET['invite'] ) && function_exists( 'WC' ) && WC()->session ) {
			if ( ! WC()->session->has_session() ) {
				WC()->session->set_customer_session_cookie( true );
			}
			WC()->session->set( 'rinnovomem_invite', sanitize_text_field( wp_unslash( $_GET['invite'] ) ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended
		}
	}

	/**
	 * Products of an invite-only tier need a valid invite.
	 *
	 * @param bool $valid      Valid.
	 * @param int  $product_id Product.
	 * @param int  $qty        Quantity.
	 * @return bool
	 */
	public static function invite_only( $valid, $product_id, $qty ) {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended, WordPress.Security.NonceVerification.Missing -- Add to cart has no nonce.
		$variation = isset( $_REQUEST['variation_id'] ) ? absint( $_REQUEST['variation_id'] ) : 0;
		$tier      = RINNOVOMEM_Tiers::get( RINNOVOMEM_Tiers::for_product( $variation ? $variation : $product_id ) );
		if ( ! $valid || ! $tier || '' === trim( $tier['invite'] ) || current_user_can( 'manage_woocommerce' ) ) {
			return $valid;
		}
		$codes = array_filter( array_map( 'trim', explode( ',', strtolower( $tier['invite'] ) ) ) );
		$have  = WC()->session ? strtolower( (string) WC()->session->get( 'rinnovomem_invite' ) ) : '';
		if ( '' === $have || ! in_array( $have, $codes, true ) ) {
			/* translators: %s: tier. */
			wc_add_notice( sprintf( __( '%s is by invitation only. Please use the invite link you received.', self::TD ), $tier['name'] ), 'error' );
			return false;
		}
		return $valid;
	}

	// ---------------------------------------------------------------- Gifts.

	/**
	 * Whether a product can be given as a gift (one-time products that give a tier or courses).
	 *
	 * @param WC_Product $product Product.
	 * @return bool
	 */
	public static function giftable( $product ) {
		return $product instanceof WC_Product && ! RINNOVO_Product::is_subscription( $product ) && ( '' !== RINNOVOMEM_Tiers::for_product( $product->get_id() ) || RINNOVOMEM_Courses::rows_for( $product->get_id() ) );
	}

	/**
	 * Gift fields on the product page.
	 */
	public static function gift_fields() {
		global $product;
		if ( ! self::giftable( $product ) ) {
			return;
		}
		echo '<details class="rinnovomem-gift"><summary>' . esc_html__( 'Give this as a gift', self::TD ) . '</summary><p><label>' . esc_html__( 'Recipient\'s email', self::TD ) . '<br /><input type="email" name="rinnovomem_gift_email" maxlength="100" /></label></p><p><label>' . esc_html__( 'Their name', self::TD ) . '<br /><input type="text" name="rinnovomem_gift_name" maxlength="80" /></label></p><p><label>' . esc_html__( 'Message (optional)', self::TD ) . '<br /><textarea name="rinnovomem_gift_msg" rows="2" maxlength="300"></textarea></label></p></details>';
	}

	/**
	 * Keep the gift on the cart item.
	 *
	 * @param array $data       Data.
	 * @param int   $product_id Product.
	 * @return array
	 */
	public static function gift_cart( $data, $product_id ) {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Add to cart has no nonce.
		$email = isset( $_POST['rinnovomem_gift_email'] ) ? sanitize_email( wp_unslash( $_POST['rinnovomem_gift_email'] ) ) : '';
		if ( is_email( $email ) && self::giftable( wc_get_product( $product_id ) ) ) {
			$data['rinnovomem_gift'] = array(
				'email' => $email,
				'name'  => isset( $_POST['rinnovomem_gift_name'] ) ? substr( sanitize_text_field( wp_unslash( $_POST['rinnovomem_gift_name'] ) ), 0, 80 ) : '',
				'msg'   => isset( $_POST['rinnovomem_gift_msg'] ) ? substr( sanitize_textarea_field( wp_unslash( $_POST['rinnovomem_gift_msg'] ) ), 0, 300 ) : '',
			);
		}
		// phpcs:enable
		return $data;
	}

	/**
	 * Show it in the cart.
	 *
	 * @param array $data Lines.
	 * @param array $item Cart item.
	 * @return array
	 */
	public static function gift_item_data( $data, $item ) {
		if ( ! empty( $item['rinnovomem_gift'] ) ) {
			$data[] = array(
				'key'   => __( 'Gift for', self::TD ),
				'value' => trim( $item['rinnovomem_gift']['name'] . ' <' . $item['rinnovomem_gift']['email'] . '>' ),
			);
		}
		return $data;
	}

	/**
	 * Keep it on the order line.
	 *
	 * @param WC_Order_Item_Product $line   Line.
	 * @param string                $key    Cart key.
	 * @param array                 $values Cart item.
	 */
	public static function gift_line( $line, $key, $values ) {
		if ( ! empty( $values['rinnovomem_gift'] ) ) {
			$line->add_meta_data( '_rinnovomem_gift', $values['rinnovomem_gift'], true );
			$line->add_meta_data( __( 'Gift for', self::TD ), $values['rinnovomem_gift']['email'], true );
		}
	}

	/**
	 * Paid gifts: the recipient gets the access (an account is made for them when needed) and an email.
	 *
	 * @param int $order_id Order.
	 */
	public static function gift_paid( $order_id ) {
		$order = wc_get_order( $order_id );
		if ( ! $order ) {
			return;
		}
		foreach ( $order->get_items() as $line ) {
			$gift = $line->get_meta( '_rinnovomem_gift' );
			if ( ! is_array( $gift ) || $line->get_meta( '_rinnovomem_gift_given' ) ) {
				continue;
			}
			$user = get_user_by( 'email', $gift['email'] );
			$uid  = $user ? $user->ID : wc_create_new_customer( $gift['email'], '', '', array( 'first_name' => $gift['name'] ) );
			if ( is_wp_error( $uid ) ) {
				$order->add_order_note( __( 'The gift could not be given: ', self::TD ) . $uid->get_error_message() );
				continue;
			}
			$pid  = $line->get_variation_id() ? $line->get_variation_id() : $line->get_product_id();
			$tier = RINNOVOMEM_Tiers::for_product( $pid );
			if ( '' !== $tier ) {
				RINNOVOMEM_Ledger::add( $uid, 'tier', $tier, 'gift', $order->get_id(), time(), 0, $line->get_name() );
			}
			foreach ( RINNOVOMEM_Courses::rows_for( $pid ) as $row ) {
				foreach ( $row['courses'] as $course ) {
					RINNOVOMEM_Ledger::add( $uid, 'course', (string) $course, 'gift', $order->get_id(), time(), (int) $row['days'] ? time() + (int) $row['days'] * DAY_IN_SECONDS : 0, $line->get_name() );
				}
			}
			$line->update_meta_data( '_rinnovomem_gift_given', $uid );
			$line->save();
			RINNOVOMEM_Members::sync( $uid );
			$mailer = WC()->mailer();
			/* translators: %s: buyer. */
			$subject = sprintf( __( '%s sent you a gift', self::TD ), $order->get_billing_first_name() );
			$mailer->send( $gift['email'], $subject, $mailer->wrap_message( $subject, '<p>' . esc_html( sprintf( /* translators: %s: product. */ __( 'You now have access to %s.', self::TD ), $line->get_name() ) ) . '</p>' . ( '' !== $gift['msg'] ? '<blockquote>' . esc_html( $gift['msg'] ) . '</blockquote>' : '' ) . '<p><a href="' . esc_url( wc_get_account_endpoint_url( self::ENDPOINT ) ) . '">' . esc_html__( 'Open My Membership', self::TD ) . '</a></p>' ) );
			/* translators: %s: email. */
			$order->add_order_note( sprintf( __( 'Gift given to %s.', self::TD ), $gift['email'] ) );
		}
		// Gifts are for the recipient, not the buyer: their own purchase list leaves them out.
	}

	// ---------------------------------------------------------------- REST API and webhooks.

	/**
	 * Routes: /rinnovomem/v1/me and /rinnovomem/v1/members/{id}.
	 */
	public static function rest() {
		register_rest_route(
			'rinnovomem/v1',
			'/me',
			array(
				'methods'             => 'GET',
				'permission_callback' => 'is_user_logged_in',
				'callback'            => static function () {
					return rest_ensure_response( self::member_data( get_current_user_id() ) );
				},
			)
		);
		register_rest_route(
			'rinnovomem/v1',
			'/members/(?P<id>\d+)',
			array(
				'methods'             => 'GET',
				'permission_callback' => static function () {
					return current_user_can( 'manage_woocommerce' );
				},
				'callback'            => static function ( $req ) {
					$user = get_userdata( (int) $req['id'] );
					return $user ? rest_ensure_response( self::member_data( $user->ID ) ) : new WP_Error( 'not_found', __( 'Member not found.', self::TD ), array( 'status' => 404 ) );
				},
			)
		);
	}

	/**
	 * A member's access as data.
	 *
	 * @param int $user_id User.
	 * @return array
	 */
	public static function member_data( $user_id ) {
		$tiers = array();
		foreach ( RINNOVOMEM_Members::held( $user_id ) as $id => $h ) {
			$tiers[] = array(
				'id'     => $id,
				'name'   => RINNOVOMEM_Tiers::name( $id ),
				'group'  => RINNOVOMEM_Tiers::group( $id ),
				'since'  => gmdate( 'c', (int) $h['since'] ),
				'source' => $h['source'],
			);
		}
		$b = RINNOVOMEM_Credits::balance( $user_id );
		return array(
			'user'         => (int) $user_id,
			'tiers'        => $tiers,
			'courses'      => array_values( self::courses( $user_id ) ),
			'credits'      => array(
				'unlimited' => $b['unlimited'],
				'plan'      => $b['plan_credits'],
				'bought'    => $b['bought'],
				'resets'    => $b['resets'] ? gmdate( 'c', $b['resets'] ) : null,
			),
			'entitlements' => array_map(
				static function ( $r ) {
					return array(
						'kind'   => $r->kind,
						'target' => $r->target,
						'source' => $r->source,
						'starts' => $r->starts_at,
						'ends'   => $r->ends_at,
						'status' => $r->status,
					);
				},
				RINNOVOMEM_Ledger::all( $user_id )
			),
		);
	}

	/**
	 * Post an event to the webhook (signed with the secret, not waiting for the answer).
	 *
	 * @param string $event Event.
	 * @param array  $data  Data.
	 */
	public static function send( $event, $data ) {
		$url = (string) RINNOVOMEM_Settings::get( 'webhook_url' );
		if ( '' === $url || ! wp_http_validate_url( $url ) ) {
			return;
		}
		$body = wp_json_encode(
			array(
				'event' => $event,
				'time'  => gmdate( 'c' ),
				'data'  => $data,
			)
		);
		wp_remote_post(
			$url,
			array(
				'blocking' => false,
				'timeout'  => 3,
				'headers'  => array(
					'Content-Type'        => 'application/json',
					'X-Rinnovo-Event'     => $event,
					'X-Rinnovo-Signature' => hash_hmac( 'sha256', (string) $body, (string) RINNOVOMEM_Settings::get( 'webhook_secret' ) ),
				),
				'body'     => $body,
			)
		);
	}

	/**
	 * Access changed.
	 *
	 * @param int $user_id User.
	 * @param int $old     Old rank.
	 * @param int $rank    New rank.
	 */
	public static function hook_access( $user_id, $old, $rank ) {
		self::send( 'member.access_changed', array( 'user' => (int) $user_id, 'tiers' => array_keys( RINNOVOMEM_Members::held( $user_id ) ) ) );
	}

	/**
	 * Entitlement added.
	 *
	 * @param int   $id  Row.
	 * @param array $row Row.
	 */
	public static function hook_entitlement( $id, $row ) {
		self::send( 'member.entitlement_added', array_merge( array( 'id' => (int) $id ), $row ) );
	}

	/**
	 * Tab.
	 *
	 * @param array $tabs Tabs.
	 * @return array
	 */
	public static function tab( $tabs ) {
		$tabs['extras'] = __( 'Dashboard and API', self::TD );
		return $tabs;
	}

	/**
	 * Tab content.
	 */
	public static function render() {
		$s = RINNOVOMEM_Settings::all();
		echo '<h2>' . esc_html__( 'My Membership dashboard', self::TD ) . '</h2><p>' . esc_html__( 'Members get "My Membership" in My Account (or put [rinnovo_my_membership] on any page): plans, status and renewal, credits left, courses, downloads this month, what unlocks next and an upgrade suggestion. [rinnovo_courses] lists their courses.', self::TD ) . '</p>';
		echo '<table class="form-table" role="presentation"><tr><th>' . esc_html__( 'Announcement', self::TD ) . '</th><td><textarea name="announcement" rows="3" class="large-text">' . esc_textarea( (string) $s['announcement'] ) . '</textarea><p class="description">' . esc_html__( 'Shown at the top of every member\'s dashboard (empty = none).', self::TD ) . '</p></td></tr></table>';
		echo '<h2>' . esc_html__( 'REST API and webhooks', self::TD ) . '</h2><p>' . esc_html__( 'GET /wp-json/rinnovomem/v1/me (the logged-in member) and /wp-json/rinnovomem/v1/members/{user ID} (shop managers): tiers, courses, credits and entitlements.', self::TD ) . '</p>';
		echo '<table class="form-table" role="presentation"><tr><th>' . esc_html__( 'Webhook URL', self::TD ) . '</th><td><input type="url" class="regular-text" name="webhook_url" value="' . esc_attr( (string) $s['webhook_url'] ) . '" placeholder="https://" /><p class="description">' . esc_html__( 'Receives member.access_changed and member.entitlement_added as JSON.', self::TD ) . '</p></td></tr><tr><th>' . esc_html__( 'Webhook secret', self::TD ) . '</th><td><input type="text" class="regular-text" name="webhook_secret" value="' . esc_attr( (string) $s['webhook_secret'] ) . '" autocomplete="off" /><p class="description">' . esc_html__( 'Each request carries X-Rinnovo-Signature: the HMAC-SHA256 of the body with this secret.', self::TD ) . '</p></td></tr></table>';
		submit_button();
	}

	/**
	 * Save.
	 */
	public static function save() {
		// phpcs:disable WordPress.Security.NonceVerification.Missing -- Checked by RINNOVOMEM_Admin::save().
		RINNOVOMEM_Settings::save(
			array(
				'announcement'   => isset( $_POST['announcement'] ) ? wp_kses_post( wp_unslash( $_POST['announcement'] ) ) : '',
				'webhook_url'    => isset( $_POST['webhook_url'] ) ? esc_url_raw( wp_unslash( $_POST['webhook_url'] ) ) : '',
				'webhook_secret' => isset( $_POST['webhook_secret'] ) ? sanitize_text_field( wp_unslash( $_POST['webhook_secret'] ) ) : '',
			)
		);
		// phpcs:enable
		set_transient( 'rinnovomem_tab_note_' . get_current_user_id(), array( 'success', __( 'Settings saved.', self::TD ) ), 60 );
	}
}
