<?php
/**
 * My Account > My Content: membership and status, plan changes with credit, team, everything unlocked and
 * coming soon, courses, the files library, perks, billing and directory profile, in one place.
 *
 * @package RinnovoMembership
 */

defined( 'ABSPATH' ) || exit;

/**
 * My Content.
 */
class RINNOVOMEM_Account {

	const TD       = 'rinnovo-membership-content-pro';
	const ENDPOINT = 'my-content';

	/**
	 * Hook everything up.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'add_endpoint' ) );
		add_action( 'init', array( __CLASS__, 'maybe_flush' ), 99 );
		add_filter( 'woocommerce_get_query_vars', array( __CLASS__, 'query_vars' ) );
		add_filter( 'woocommerce_account_menu_items', array( __CLASS__, 'menu' ), 15 );
		add_filter( 'woocommerce_endpoint_' . self::ENDPOINT . '_title', array( __CLASS__, 'title' ) );
		add_action( 'woocommerce_account_' . self::ENDPOINT . '_endpoint', array( __CLASS__, 'content' ) );
		add_action( 'template_redirect', array( __CLASS__, 'save_profile' ), 7 );
	}

	/**
	 * Register my-account/my-content/.
	 */
	public static function add_endpoint() {
		add_rewrite_endpoint( self::ENDPOINT, EP_ROOT | EP_PAGES );
	}

	/**
	 * Refresh permalinks once after installing or updating, so the page is found.
	 */
	public static function maybe_flush() {
		if ( get_option( 'rinnovomem_rewrite_version' ) !== RINNOVOMEM_VERSION ) {
			flush_rewrite_rules( false );
			update_option( 'rinnovomem_rewrite_version', RINNOVOMEM_VERSION );
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
	 * "My Content" after Dashboard.
	 *
	 * @param array $items Items.
	 * @return array
	 */
	public static function menu( $items ) {
		$out = array();
		foreach ( $items as $key => $label ) {
			$out[ $key ] = $label;
			if ( 'dashboard' === $key ) {
				$out[ self::ENDPOINT ] = RINNOVOMEM_Settings::text( 'account_label' );
			}
		}
		if ( ! isset( $out[ self::ENDPOINT ] ) ) {
			$out = array( self::ENDPOINT => RINNOVOMEM_Settings::text( 'account_label' ) ) + $out;
		}
		return $out;
	}

	/**
	 * Title.
	 *
	 * @return string
	 */
	public static function title() {
		return RINNOVOMEM_Settings::text( 'account_label' );
	}

	/**
	 * Content the member can open, and content that unlocks soon.
	 *
	 * @param int $user_id User.
	 * @return array unlocked (WP_Post[]), coming ([post, unlock]).
	 */
	public static function content_for( $user_id ) {
		$posts = get_posts(
			array(
				'post_type'      => RINNOVOMEM_Rules::post_types(),
				'post_status'    => 'publish',
				'posts_per_page' => 150,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => RINNOVOMEM_Rules::TIER,
						'value'   => array_merge( array( RINNOVOMEM_Tiers::FREE ), array_keys( RINNOVOMEM_Tiers::all() ) ),
						'compare' => 'IN',
					),
				),
			)
		);
		$out = array(
			'unlocked' => array(),
			'coming'   => array(),
		);
		foreach ( $posts as $post ) {
			$r = RINNOVOMEM_Rules::check( RINNOVOMEM_Rules::requirement( $post->ID ), $user_id );
			if ( $r['ok'] ) {
				$out['unlocked'][] = $post;
			} elseif ( 'drip' === $r['reason'] ) {
				$out['coming'][] = array( $post, $r['unlock'] );
			}
		}
		usort(
			$out['coming'],
			static function ( $a, $b ) {
				return $a[1] - $b[1];
			}
		);
		return $out;
	}

	/**
	 * The page.
	 */
	public static function content() {
		$user_id = get_current_user_id();
		$held    = RINNOVOMEM_Members::held( $user_id );
		echo '<div class="rinnovomem-account">';
		self::membership( $user_id, $held );
		if ( $held ) {
			self::plans( $user_id );
			self::team( $user_id );
			self::unlocked( $user_id );
			self::courses( $user_id );
			self::library( $user_id );
			self::perks( $user_id );
			self::billing( $user_id );
			self::profile( $user_id );
		}
		echo '</div>';
	}

	/**
	 * Membership cards.
	 *
	 * @param int   $user_id User.
	 * @param array $held    Held tiers.
	 */
	private static function membership( $user_id, $held ) {
		if ( ! $held ) {
			echo '<section class="rinnovomem-section"><h3>' . esc_html__( 'Your membership', self::TD ) . '</h3><p>' . esc_html__( 'You have no membership yet.', self::TD ) . '</p><ul class="rinnovomem-join">';
			foreach ( RINNOVOMEM_Tiers::all() as $id => $tier ) {
				echo '<li>' . wp_kses_post( RINNOVOMEM_Tiers::badge( $id ) ) . ' <a class="button" href="' . esc_url( RINNOVOMEM_Tiers::join_url( $id ) ) . '">' . esc_html__( 'Join', self::TD ) . '</a></li>';
			}
			echo '</ul></section>';
			return;
		}
		uksort(
			$held,
			static function ( $a, $b ) {
				return RINNOVOMEM_Tiers::rank( $b ) - RINNOVOMEM_Tiers::rank( $a );
			}
		);
		echo '<section class="rinnovomem-section"><h3>' . esc_html__( 'Your membership', self::TD ) . '</h3>';
		foreach ( $held as $tier => $h ) {
			$sub = $h['sub'] ? RINNOVO_Subscriptions::get( $h['sub'] ) : null;
			echo '<div class="rinnovomem-card"><div class="rinnovomem-card__head">' . wp_kses_post( RINNOVOMEM_Tiers::badge( $tier ) );
			/* translators: %s: date. */
			echo ' <span class="rinnovomem-card__since">' . esc_html( sprintf( __( 'Member since %s', self::TD ), wp_date( get_option( 'date_format' ), $h['since'] ) ) ) . '</span></div><p>';
			if ( 'subscription' === $h['source'] && $sub ) {
				echo esc_html( RINNOVO_Subscriptions::price_string( $sub->recurring_amount, $sub->period, $sub->interval_count, $sub->currency ) ) . ' · ' . esc_html( RINNOVO_Subscriptions::status_label( $sub->status ) );
				if ( 'pending-cancel' === $sub->status ) {
					/* translators: %s: date. */
					echo '<br />' . esc_html( sprintf( __( 'Your access ends on %s.', self::TD ), RINNOVO_Subscriptions::date_label( $sub->next_payment ) ) );
				} elseif ( 'on-hold' === $sub->status ) {
					echo '<br /><strong>' . esc_html__( 'Your last payment did not go through. Please pay or update your card in Subscriptions to keep your access.', self::TD ) . '</strong>';
				} elseif ( $sub->next_payment ) {
					/* translators: %s: date. */
					echo '<br />' . esc_html( sprintf( __( 'Renews on %s', self::TD ), RINNOVO_Subscriptions::date_label( $sub->next_payment ) ) );
				}
			} elseif ( 'team' === $h['source'] && $sub ) {
				$owner = get_userdata( (int) $sub->user_id );
				/* translators: %s: name. */
				echo esc_html( sprintf( __( 'Shared with you by %s.', self::TD ), $owner ? $owner->display_name : '—' ) );
			} elseif ( 'order' === $h['source'] ) {
				esc_html_e( 'Lifetime access.', self::TD );
			} else {
				$manual = (array) get_user_meta( $user_id, '_rinnovomem_manual', true );
				$until  = isset( $manual[ $tier ]['until'] ) ? (int) $manual[ $tier ]['until'] : 0;
				/* translators: %s: date. */
				echo esc_html( $until ? sprintf( __( 'Given by us until %s.', self::TD ), wp_date( get_option( 'date_format' ), $until ) ) : __( 'Given by us.', self::TD ) );
			}
			echo '</p></div>';
		}
		echo '</section>';
	}

	/**
	 * Change plan.
	 *
	 * @param int $user_id User.
	 */
	private static function plans( $user_id ) {
		$sub = RINNOVOMEM_Switch::current( $user_id );
		if ( ! $sub ) {
			return;
		}
		$options = RINNOVOMEM_Switch::options( $sub );
		if ( ! $options ) {
			return;
		}
		$credit = RINNOVOMEM_Switch::credit( $sub );
		$labels = array(
			'upgrade'   => __( 'Upgrade', self::TD ),
			'downgrade' => __( 'Downgrade', self::TD ),
			'switch'    => __( 'Switch', self::TD ),
		);
		echo '<section class="rinnovomem-section"><h3>' . esc_html__( 'Change plan', self::TD ) . '</h3>';
		if ( $credit > 0 ) {
			/* translators: %s: amount. */
			echo '<p>' . esc_html( sprintf( __( 'You have %s of unused time on your current plan; it comes off your new plan\'s first payment.', self::TD ), wp_strip_all_tags( wc_price( $credit, array( 'currency' => $sub->currency ) ) ) ) ) . '</p>';
		}
		echo '<form method="post" class="rinnovomem-plans">';
		wp_nonce_field( 'rinnovomem_switch', 'rinnovomem_switch_nonce' );
		foreach ( $options as $o ) {
			$plan  = RINNOVO_Product::plan( $o['product'] );
			$price = wp_strip_all_tags( wc_price( wc_get_price_to_display( $o['product'] ) ) ) . ( $plan ? ' ' . RINNOVO_Subscriptions::period_string( $plan['period'], $plan['interval'] ) : '' );
			echo '<div class="rinnovomem-plan">' . wp_kses_post( RINNOVOMEM_Tiers::badge( $o['tier'] ) ) . ' <span>' . esc_html( $o['product']->get_name() ) . ' · ' . esc_html( $price ) . '</span> <button type="submit" class="button" name="rinnovomem_switch_to" value="' . (int) $o['product']->get_id() . '">' . esc_html( $labels[ $o['kind'] ] ) . '</button></div>';
		}
		echo '</form></section>';
	}

	/**
	 * Team.
	 *
	 * @param int $user_id User.
	 */
	private static function team( $user_id ) {
		foreach ( RINNOVO_Subscriptions::for_user( $user_id ) as $sub ) {
			$seats = RINNOVOMEM_Teams::seats( $sub );
			if ( ! $seats || ! RINNOVOMEM_Members::sub_gives_access( $sub ) ) {
				continue;
			}
			$list = RINNOVOMEM_Teams::for_subscription( $sub->id );
			echo '<section class="rinnovomem-section"><h3>' . esc_html__( 'Your team', self::TD ) . '</h3>';
			/* translators: 1: seats used, 2: seats. */
			echo '<p>' . esc_html( sprintf( __( '%1$d of %2$d seats used.', self::TD ), count( $list ), $seats ) ) . '</p>';
			if ( $list ) {
				echo '<table class="shop_table">';
				foreach ( $list as $seat ) {
					$member = $seat->user_id ? get_userdata( (int) $seat->user_id ) : null;
					echo '<tr><td>' . esc_html( $member ? $member->display_name . ' (' . $seat->email . ')' : $seat->email ) . '</td><td>' . esc_html( 'active' === $seat->status ? __( 'Member', self::TD ) : __( 'Invited', self::TD ) ) . '</td><td><form method="post">';
					wp_nonce_field( 'rinnovomem_team', 'rinnovomem_team_nonce' );
					echo '<input type="hidden" name="rinnovomem_team_do" value="remove" /><input type="hidden" name="rinnovomem_seat" value="' . (int) $seat->id . '" /><button type="submit" class="button">' . esc_html__( 'Remove', self::TD ) . '</button></form></td></tr>';
				}
				echo '</table>';
			}
			if ( count( $list ) < $seats ) {
				echo '<form method="post" class="rinnovomem-invite">';
				wp_nonce_field( 'rinnovomem_team', 'rinnovomem_team_nonce' );
				echo '<input type="hidden" name="rinnovomem_team_do" value="invite" /><input type="hidden" name="rinnovomem_sub" value="' . (int) $sub->id . '" /><label class="screen-reader-text" for="rinnovomem-email-' . (int) $sub->id . '">' . esc_html__( 'Email', self::TD ) . '</label><input type="email" id="rinnovomem-email-' . (int) $sub->id . '" name="rinnovomem_email" required placeholder="' . esc_attr__( 'name@company.com', self::TD ) . '" /> <button type="submit" class="button alt">' . esc_html__( 'Invite', self::TD ) . '</button></form>';
			}
			echo '</section>';
		}
		foreach ( RINNOVOMEM_Teams::seats_for_user( $user_id ) as $seat ) {
			$owner = get_userdata( (int) $seat->owner_id );
			echo '<section class="rinnovomem-section"><h3>' . esc_html__( 'Team membership', self::TD ) . '</h3><form method="post">';
			wp_nonce_field( 'rinnovomem_team', 'rinnovomem_team_nonce' );
			/* translators: %s: name. */
			echo '<p>' . esc_html( sprintf( __( 'You are on %s\'s team.', self::TD ), $owner ? $owner->display_name : '—' ) ) . ' <input type="hidden" name="rinnovomem_team_do" value="remove" /><input type="hidden" name="rinnovomem_seat" value="' . (int) $seat->id . '" /><button type="submit" class="button">' . esc_html__( 'Leave the team', self::TD ) . '</button></p></form></section>';
		}
	}

	/**
	 * Unlocked and coming content.
	 *
	 * @param int $user_id User.
	 */
	private static function unlocked( $user_id ) {
		$c = self::content_for( $user_id );
		echo '<section class="rinnovomem-section"><h3>' . esc_html__( 'Your content', self::TD ) . '</h3>';
		if ( $c['unlocked'] ) {
			echo '<ul class="rinnovomem-content">';
			foreach ( array_slice( $c['unlocked'], 0, 40 ) as $post ) {
				$type = get_post_type_object( $post->post_type );
				echo '<li><a href="' . esc_url( get_permalink( $post ) ) . '">' . esc_html( get_the_title( $post ) ) . '</a> <span class="rinnovomem-content__type">' . esc_html( $type ? $type->labels->singular_name : '' ) . '</span></li>';
			}
			echo '</ul>';
		}
		$terms = get_terms(
			array(
				'taxonomy'   => get_taxonomies( array( 'public' => true ) ),
				'hide_empty' => true,
				'meta_query' => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'     => RINNOVOMEM_Rules::TIER,
						'value'   => RINNOVOMEM_Members::tiers_included( $user_id ),
						'compare' => 'IN',
					),
				),
			)
		);
		if ( is_array( $terms ) && $terms ) {
			echo '<p>' . esc_html__( 'Members-only sections:', self::TD ) . ' ';
			$links = array();
			foreach ( $terms as $term ) {
				$links[] = '<a href="' . esc_url( get_term_link( $term ) ) . '">' . esc_html( $term->name ) . '</a>';
			}
			echo wp_kses_post( implode( ', ', $links ) ) . '</p>';
		}
		if ( ! $c['unlocked'] && ! $terms ) {
			echo '<p>' . esc_html__( 'Everything for members will appear here.', self::TD ) . '</p>';
		}
		if ( $c['coming'] ) {
			echo '<h4>' . esc_html__( 'Coming soon for you', self::TD ) . '</h4><ul class="rinnovomem-content rinnovomem-content--coming">';
			foreach ( array_slice( $c['coming'], 0, 20 ) as $row ) {
				echo '<li>' . esc_html( get_the_title( $row[0] ) ) . ' <span class="rinnovomem-content__type">' . esc_html( wp_date( get_option( 'date_format' ), $row[1] ) ) . '</span></li>';
			}
			echo '</ul>';
		}
		echo '</section>';
	}

	/**
	 * Courses of the member's tiers.
	 *
	 * @param int $user_id User.
	 */
	private static function courses( $user_id ) {
		$ids = array_map( 'intval', (array) get_user_meta( $user_id, '_rinnovomem_courses', true ) );
		$ids = array_filter( $ids, static function ( $id ) { return 'publish' === get_post_status( $id ); } );
		if ( ! $ids ) {
			return;
		}
		echo '<section class="rinnovomem-section"><h3>' . esc_html__( 'Your courses', self::TD ) . '</h3><ul class="rinnovomem-content">';
		foreach ( $ids as $id ) {
			echo '<li><a href="' . esc_url( get_permalink( $id ) ) . '">' . esc_html( get_the_title( $id ) ) . '</a></li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Files library.
	 *
	 * @param int $user_id User.
	 */
	private static function library( $user_id ) {
		$files = RINNOVOMEM_Files::library( $user_id );
		if ( $files ) {
			echo '<section class="rinnovomem-section"><h3>' . esc_html__( 'Your library', self::TD ) . '</h3>' . wp_kses_post( RINNOVOMEM_Files::library_html( $files ) ) . '</section>';
		}
	}

	/**
	 * Perks.
	 *
	 * @param int $user_id User.
	 */
	private static function perks( $user_id ) {
		$perks = RINNOVOMEM_Perks::of( $user_id );
		$lines = array();
		foreach ( $perks['discounts'] as $d ) {
			$names = array();
			foreach ( $d[1] as $cat ) {
				$term = get_term( $cat, 'product_cat' );
				if ( $term && ! is_wp_error( $term ) ) {
					$names[] = $term->name;
				}
			}
			/* translators: 1: percent, 2: categories. */
			$lines[] = $names ? sprintf( __( '%1$s%% off %2$s', self::TD ), wc_format_localized_decimal( $d[0] ), implode( ', ', $names ) ) : sprintf( __( '%s%% off in the store', self::TD ), wc_format_localized_decimal( $d[0] ) );
		}
		if ( $perks['free_shipping'] ) {
			$lines[] = __( 'Free shipping on every order', self::TD );
		}
		foreach ( $perks['free_products'] as $id ) {
			$p = wc_get_product( $id );
			if ( $p ) {
				/* translators: %s: product. */
				$lines[] = sprintf( __( 'Free: %s', self::TD ), '<a href="' . esc_url( $p->get_permalink() ) . '">' . esc_html( $p->get_name() ) . '</a>' );
			}
		}
		if ( ! $lines ) {
			return;
		}
		echo '<section class="rinnovomem-section"><h3>' . esc_html__( 'Your perks', self::TD ) . '</h3><ul class="rinnovomem-perks">';
		foreach ( $lines as $line ) {
			echo '<li>' . wp_kses_post( $line ) . '</li>';
		}
		echo '</ul></section>';
	}

	/**
	 * Billing.
	 *
	 * @param int $user_id User.
	 */
	private static function billing( $user_id ) {
		$sub = RINNOVOMEM_Switch::current( $user_id );
		if ( ! $sub || (int) $sub->user_id !== $user_id ) {
			return;
		}
		echo '<section class="rinnovomem-section"><h3>' . esc_html__( 'Billing', self::TD ) . '</h3><table class="shop_table">';
		foreach ( array_slice( RINNOVO_Subscriptions::orders( $sub ), 0, 6 ) as $order ) {
			echo '<tr><td><a href="' . esc_url( $order->get_view_order_url() ) . '">#' . esc_html( $order->get_order_number() ) . '</a></td><td>' . esc_html( wc_format_datetime( $order->get_date_created() ) ) . '</td><td>' . wp_kses_post( $order->get_formatted_order_total() ) . '</td><td>' . esc_html( wc_get_order_status_name( $order->get_status() ) ) . '</td></tr>';
		}
		echo '</table><p><a class="button" href="' . esc_url( wc_get_account_endpoint_url( RINNOVO_Account::endpoint() ) ) . '">' . esc_html__( 'Manage subscription, card and cancellation', self::TD ) . '</a></p></section>';
	}

	/**
	 * Directory profile.
	 *
	 * @param int $user_id User.
	 */
	private static function profile( $user_id ) {
		if ( 'off' === RINNOVOMEM_Settings::get( 'directory' ) ) {
			return;
		}
		?>
		<section class="rinnovomem-section">
			<h3><?php esc_html_e( 'Member directory', 'rinnovo-membership-content-pro' ); ?></h3>
			<form method="post">
				<?php wp_nonce_field( 'rinnovomem_profile', 'rinnovomem_profile_nonce' ); ?>
				<p><label><input type="checkbox" name="rinnovomem_listed" value="yes" <?php checked( RINNOVOMEM_Directory::listed( $user_id ) ); ?> /> <?php esc_html_e( 'Show me in the member directory', 'rinnovo-membership-content-pro' ); ?></label></p>
				<p><label for="rinnovomem-headline"><?php esc_html_e( 'Headline', 'rinnovo-membership-content-pro' ); ?></label><br />
				<input type="text" id="rinnovomem-headline" name="rinnovomem_headline" maxlength="120" value="<?php echo esc_attr( get_user_meta( $user_id, 'rinnovomem_headline', true ) ); ?>" placeholder="<?php esc_attr_e( 'Designer in Dubai, loves running', 'rinnovo-membership-content-pro' ); ?>" style="width:100%" /></p>
				<p><label for="rinnovomem-bio"><?php esc_html_e( 'About you', 'rinnovo-membership-content-pro' ); ?></label><br />
				<textarea id="rinnovomem-bio" name="rinnovomem_bio" rows="3" style="width:100%"><?php echo esc_textarea( get_the_author_meta( 'description', $user_id ) ); ?></textarea></p>
				<button type="submit" class="button"><?php esc_html_e( 'Save profile', 'rinnovo-membership-content-pro' ); ?></button>
			</form>
		</section>
		<?php
	}

	/**
	 * Save the directory profile.
	 */
	public static function save_profile() {
		if ( ! isset( $_POST['rinnovomem_profile_nonce'] ) || ! is_user_logged_in() ) {
			return;
		}
		check_admin_referer( 'rinnovomem_profile', 'rinnovomem_profile_nonce' );
		$user = get_current_user_id();
		update_user_meta( $user, 'rinnovomem_listed', ! empty( $_POST['rinnovomem_listed'] ) ? 'yes' : 'no' );
		update_user_meta( $user, 'rinnovomem_headline', isset( $_POST['rinnovomem_headline'] ) ? sanitize_text_field( wp_unslash( $_POST['rinnovomem_headline'] ) ) : '' );
		if ( isset( $_POST['rinnovomem_bio'] ) ) {
			wp_update_user(
				array(
					'ID'          => $user,
					'description' => sanitize_textarea_field( wp_unslash( $_POST['rinnovomem_bio'] ) ),
				)
			);
		}
		wc_add_notice( __( 'Your profile is saved.', self::TD ) );
		wp_safe_redirect( wc_get_account_endpoint_url( self::ENDPOINT ) );
		exit;
	}
}
